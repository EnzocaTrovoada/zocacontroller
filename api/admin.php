<?php
/**
 * A parte que só você vê: quem entrou no site e o que cada um pode.
 *
 * Ser admin é uma coluna ligada NA MÃO no banco. Não existe tela pra promover
 * ninguém, e isso é de propósito: um botão "tornar admin" é um botão que um
 * dia alguém clica sem querer.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

$st = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$st->execute([$uid]);
if (!(int) $st->fetchColumn()) {
    /* 404 e não 403: quem não é admin não precisa nem saber que isto existe. */
    json_saida(['erro' => 'Não encontrado.'], 404);
}

/* ---------- a lista ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = db()->query(
        'SELECT u.id, u.login, u.criado_em, u.visto_em, u.admin, u.beta, u.cortesia_ate,
                u.perfis_max, u.recursos,
                (SELECT COUNT(*) FROM perfis p WHERE p.usuario_id = u.id) AS overlays
           FROM usuarios u
          ORDER BY u.visto_em IS NULL, u.visto_em DESC, u.id DESC
          LIMIT 200'
    );

    $lista = [];
    foreach ($st->fetchAll() as $u) {
        $acesso = acesso_do_usuario((int) $u['id']);
        $plano  = $acesso['ativo'] ? $acesso['plano'] : 'gratis';
        $lista[] = [
            'id'         => (int) $u['id'],
            'login'      => (string) $u['login'],
            'criado_em'  => (string) $u['criado_em'],
            'visto_em'   => $u['visto_em'],
            'admin'      => (int) $u['admin'],
            'beta'       => (int) ($u['beta'] ?? 0),
            'cortesia_ate' => $u['cortesia_ate'] ?? null,
            'plano'      => $plano,
            'overlays'   => (int) $u['overlays'],
            'perfis_max' => $u['perfis_max'] === null ? null : (int) $u['perfis_max'],
            'recursos'   => $u['recursos'] ? json_decode((string) $u['recursos'], true) : null,
            'vale'       => recursos_do_usuario((int) $u['id'], $plano),
        ];
    }

    /* OS E-MAILS DO SPOTIFY, PRA COLAR NA LISTA DE PERMISSÃO DELES.

       O app está em modo de desenvolvimento e atende cinco contas escritas à
       mão no painel do Spotify. Esta lista é só quem já conectou e ainda não
       está cadastrado — é o que evita ficar perguntando e-mail no privado. */
    $spot = [];
    try {
        $q = db()->query(
            'SELECT u.login, s.email
               FROM spotify s JOIN usuarios u ON u.id = s.usuario_id
              WHERE s.email IS NOT NULL AND s.email <> \'\'
              ORDER BY s.usuario_id DESC LIMIT 200'
        );
        $spot = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* coluna nova: quem não rodou o SQL vê lista vazia */ }

    /* ----- cupons, parceiros e o que se deve ----- */
    $cupons = [];
    $parceiros = [];
    try {
        $cupons = db()->query(
            'SELECT c.*, p.nome AS parceiro
               FROM cupons c LEFT JOIN parceiros p ON p.id = c.parceiro_id
              ORDER BY c.criado_em DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);

        /* O relatório que importa é "quanto eu devo": comissão de venda
           estornada não entra, e o que já foi pago sai do total em aberto. */
        $parceiros = db()->query(
            "SELECT p.*,
                    COALESCE(SUM(CASE WHEN c.estornada = 0 AND c.pago_em IS NULL
                                      THEN c.valor_centavos ELSE 0 END), 0) AS a_pagar,
                    COALESCE(SUM(CASE WHEN c.estornada = 0 AND c.pago_em IS NOT NULL
                                      THEN c.valor_centavos ELSE 0 END), 0) AS ja_pago,
                    COUNT(CASE WHEN c.estornada = 0 THEN 1 END) AS vendas
               FROM parceiros p LEFT JOIN comissoes c ON c.parceiro_id = p.id
              GROUP BY p.id ORDER BY p.nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* tabelas novas: quem não rodou o SQL vê listas vazias */ }

    json_saida(['eu' => $uid, 'usuarios' => $lista, 'spotify' => $spot,
                'cupons' => $cupons, 'parceiros' => $parceiros, 'padrao' => [
        'gratis' => recursos_do_plano('gratis'),
        'pro'    => recursos_do_plano('pro'),
    ]]);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');

/* ---------- parceiros, cupons e comissões ----------
   Antes do bloco de usuário porque estas ações não falam de um usuário, e
   o bloco de baixo exige um id de conta pra existir. */

$centavosDe = static fn($v) => max(0, (int) round(((float) str_replace(',', '.', (string) $v)) * 100));

if ($acao === 'parceiro_salvar') {
    $id   = (int) ($d['id'] ?? 0);
    $nome = mb_substr(trim((string) ($d['nome'] ?? '')), 0, 80);
    if ($nome === '') json_saida(['erro' => 'O parceiro precisa de nome.'], 400);

    $pct  = max(0, min(100, (float) ($d['comissao_pct'] ?? 0)));
    $cont = mb_substr(trim((string) ($d['contato'] ?? '')), 0, 160) ?: null;
    $lig  = empty($d['ligado']) ? 0 : 1;

    if ($id > 0) {
        db()->prepare('UPDATE parceiros SET nome = ?, contato = ?, comissao_pct = ?, ligado = ? WHERE id = ?')
            ->execute([$nome, $cont, $pct, $lig, $id]);
    } else {
        db()->prepare('INSERT INTO parceiros (nome, contato, comissao_pct, ligado) VALUES (?, ?, ?, ?)')
            ->execute([$nome, $cont, $pct, $lig]);
        $id = (int) db()->lastInsertId();
    }
    json_saida(['ok' => true, 'id' => $id]);
}

if ($acao === 'cupom_salvar') {
    require_once __DIR__ . '/lib/cupons.php';

    $cod = cupom_limpa((string) ($d['codigo'] ?? ''));
    if ($cod === '') json_saida(['erro' => 'O cupom precisa de um código.'], 400);

    $tipo = ($d['tipo'] ?? 'percentual') === 'valor' ? 'valor' : 'percentual';
    /* Percentual é número inteiro; valor fixo chega em reais e vira centavos.
       Misturar as duas unidades no mesmo campo é o erro clássico aqui, e o
       sintoma seria um cupom de "10" virar dez centavos de desconto. */
    $valor = $tipo === 'percentual'
        ? max(1, min(100, (int) ($d['valor'] ?? 0)))
        : $centavosDe($d['valor'] ?? 0);
    if ($valor <= 0) json_saida(['erro' => 'O desconto precisa ser maior que zero.'], 400);

    $parceiro = (int) ($d['parceiro_id'] ?? 0) ?: null;
    $usos_max = ($d['usos_max'] ?? '') === '' ? null : max(1, (int) $d['usos_max']);
    $ate      = trim((string) ($d['vale_ate'] ?? ''));
    $ate      = $ate === '' ? null : date('Y-m-d 23:59:59', strtotime($ate) ?: time());

    /* Cupom de parceiro nunca entra na lista pública, mesmo que marquem
       sem querer: é o ativo dele, e a regra tem que valer aqui e não só na
       tela. */
    $publico = (!empty($d['publico']) && !$parceiro) ? 1 : 0;

    db()->prepare(
        'INSERT INTO cupons (codigo, descricao, tipo, valor, parceiro_id, usos_max, vale_ate, ligado, publico)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), tipo = VALUES(tipo),
              valor = VALUES(valor), parceiro_id = VALUES(parceiro_id),
              usos_max = VALUES(usos_max), vale_ate = VALUES(vale_ate),
              ligado = VALUES(ligado), publico = VALUES(publico)'
    )->execute([
        $cod,
        mb_substr(trim((string) ($d['descricao'] ?? '')), 0, 120) ?: null,
        $tipo, $valor, $parceiro, $usos_max, $ate,
        empty($d['ligado']) ? 0 : 1,
        $publico,
    ]);
    json_saida(['ok' => true, 'codigo' => $cod]);
}

if ($acao === 'cupom_apagar') {
    require_once __DIR__ . '/lib/cupons.php';
    /* Apagar de verdade, e não desligar: cupom desligado já existe como
       opção. Quem escolheu apagar quer que suma da lista. A comissão que ele
       gerou fica, porque a dívida com o parceiro não some junto. */
    db()->prepare('DELETE FROM cupons WHERE codigo = ?')
        ->execute([cupom_limpa((string) ($d['codigo'] ?? ''))]);
    json_saida(['ok' => true]);
}

if ($acao === 'comissao_pagar') {
    $pid = (int) ($d['parceiro_id'] ?? 0);
    if ($pid <= 0) json_saida(['erro' => 'Qual parceiro?'], 400);

    /* Marca como pago o que estava em aberto. Não apaga nada: o histórico é
       o que responde "quando eu paguei quanto pra quem". */
    $st = db()->prepare(
        'UPDATE comissoes SET pago_em = NOW()
          WHERE parceiro_id = ? AND estornada = 0 AND pago_em IS NULL'
    );
    $st->execute([$pid]);
    json_saida(['ok' => true, 'quitadas' => $st->rowCount()]);
}

/* ---------- mudar um ---------- */
$alvo = (int) ($d['id'] ?? 0);
if ($alvo <= 0) json_saida(['erro' => 'Falta dizer quem.'], 400);

/* NINGUÉM MEXE NA PRÓPRIA CONTA POR AQUI.
   Não é desconfiança: é que uma conta de admin que se rebaixa por engano não
   tem como se promover de volta sem abrir o banco. */
if ($alvo === $uid) json_saida(['erro' => 'Você não muda a sua própria conta por aqui.'], 400);

$campos = [];
$vals   = [];

if (array_key_exists('perfis_max', $d)) {
    /* Vazio volta pro que o plano dá. Guardar o número do plano na mão faria
       a pessoa parar de acompanhar mudanças futuras do plano sem perceber. */
    $v = $d['perfis_max'];
    $campos[] = 'perfis_max = ?';
    $vals[]   = ($v === null || $v === '') ? null : max(0, min(500, (int) $v));
}

/* Ligar e desligar o selo de testador. Existe pra dois casos: alguém que
   ajudou muito e merece continuar com tudo, e alguém que foi marcado por
   engano quando a cobrança abriu. */
if (array_key_exists('beta', $d)) {
    $campos[] = 'beta = ?';
    $vals[]   = !empty($d['beta']) ? 1 : 0;
}

/* PRO DADO À MÃO, COM PRAZO.

   Recebe uma data (ou vazio pra tirar). Com prazo, e não um interruptor,
   porque cortesia sem data é cortesia esquecida: seis meses depois ninguém
   lembra por que aquela conta tem Pro. */
if (array_key_exists('cortesia_ate', $d)) {
    $v = trim((string) $d['cortesia_ate']);
    $campos[] = 'cortesia_ate = ?';
    $vals[]   = $v === '' ? null : date('Y-m-d 23:59:59', strtotime($v) ?: time());
}

if (array_key_exists('recursos', $d)) {
    $r = $d['recursos'];
    if (!is_array($r) || !$r) {
        $campos[] = 'recursos = ?';
        $vals[]   = null;
    } else {
        /* Só chaves que o plano já conhece, e só valores do mesmo tipo: o
           banco não pode inventar recurso que o código não trata. */
        $molde = recursos_do_plano('pro');
        $limpo = [];
        foreach ($r as $k => $v) {
            if (!array_key_exists($k, $molde)) continue;
            $limpo[$k] = is_bool($molde[$k]) ? (bool) $v
                       : (is_int($molde[$k]) ? max(0, min(500, (int) $v)) : (string) $v);
        }
        $campos[] = 'recursos = ?';
        $vals[]   = $limpo ? json_encode($limpo, JSON_UNESCAPED_UNICODE) : null;
    }
}

if (!$campos) json_saida(['erro' => 'Nada pra mudar.'], 400);

$vals[] = $alvo;
db()->prepare('UPDATE usuarios SET ' . implode(', ', $campos) . ' WHERE id = ?')->execute($vals);

json_saida(['ok' => true]);
