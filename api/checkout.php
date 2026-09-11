<?php
/**
 * Abre a cobrança e devolve o link do checkout HOSPEDADO do Mercado Pago.
 *
 * O cartão é digitado no domínio DELES, nunca no nosso. Nosso servidor não vê,
 * não trafega e não guarda número de cartão — e é isso que mantém a gente fora
 * do escopo pesado do PCI DSS. Nunca embutir campo de cartão em página nossa,
 * nem em iframe: isso já muda o questionário de SAQ A para SAQ A-EP.
 *
 * Só responde a quem já entrou com a Twitch: sem dono, um pagamento voltaria
 * e não haveria a quem liberar.
 *
 * Devolve JSON com a URL em vez de redirecionar. Redirecionar daqui tiraria
 * do painel a chance de mostrar o erro quando o Mercado Pago recusa.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/mercadopago.php';

cors();
$quem = exige_painel();
trava('checkout', 10, 300);

$mp = mp_cfg();

/* OS CUPONS QUE VALEM AGORA.

   Só os marcados como públicos. Cupom de parceiro fica FORA: o código dele
   é o ativo dele, e numa lista dentro do site ninguém precisaria passar
   pelo link — o desconto continuaria valendo e a comissão não aconteceria.
   O parceiro teria trabalhado de graça. */
if (isset($_GET['cupons'])) {
    require_once __DIR__ . '/lib/cupons.php';

    $lista = [];
    $falhou = null;
    try {
        $q = db()->query(
            "SELECT codigo, descricao, tipo, valor, vale_ate, usos, usos_max
               FROM cupons
              WHERE publico = 1 AND ligado = 1 AND parceiro_id IS NULL
                AND (vale_ate IS NULL OR vale_ate > NOW())
                AND (usos_max IS NULL OR usos < usos_max)
              ORDER BY valor DESC LIMIT 8"
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $lista[] = [
                'codigo'    => $c['codigo'],
                'descricao' => $c['descricao'],
                'rotulo'    => $c['tipo'] === 'percentual'
                    ? ((int) $c['valor']) . '% de desconto'
                    : 'R$ ' . number_format(((int) $c['valor']) / 100, 2, ',', '.') . ' de desconto',
                'vale_ate'  => $c['vale_ate'],
                /* Quantos ainda restam, quando há limite: "faltam 3" faz
                   decidir agora, e é verdade. */
                'restam'    => $c['usos_max'] === null ? null
                    : max(0, (int) $c['usos_max'] - (int) $c['usos']),
            ];
        }
    } catch (Throwable $e) {
        $falhou = $e->getMessage();
    }

    /* LISTA VAZIA PRO ADMIN VEM COM O MOTIVO.

       Ela apareceu vazia duas vezes com cupons criados, e de fora não havia
       como saber o porquê. Pra quem administra, a resposta diz o que está
       tirando cada cupom da lista. */
    $motivo = null;
    if (!$lista) {
        $ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
        $ad->execute([(int) $quem['usuario_id']]);
        if ($ad->fetchColumn()) $motivo = cupons_motivo($falhou);
    }

    json_saida(['cupons' => $lista, 'motivo' => $motivo]);
}

/* CONFERIR UM CUPOM ANTES DE PAGAR.

   Existe pra que a pessoa veja o desconto na tela ANTES de sair do site.
   Digitar um código e só descobrir no checkout do Mercado Pago se ele valeu
   é o tipo de dúvida que faz desistir da compra. */
if (isset($_GET['cupom_teste'])) {
    require_once __DIR__ . '/lib/cupons.php';

    trava('cupom', 20, 300);

    $slug2 = (string) ($_GET['plano'] ?? 'pro');
    $pl = mp_plano_por_slug($slug2);
    if (!$pl) json_saida(['erro' => 'Esse plano não existe.'], 404);

    $v = cupom_valida((string) $_GET['cupom_teste']);
    if (empty($v['ok'])) json_saida(['ok' => false, 'erro' => $v['erro']]);

    $conta = cupom_aplica($v['cupom'], (int) $pl['preco_centavos']);
    json_saida([
        'ok'        => true,
        'codigo'    => cupom_limpa((string) $_GET['cupom_teste']),
        'rotulo'    => $conta['rotulo'],
        'de'        => $conta['de'],
        'por'       => $conta['por'],
        'descricao' => $v['cupom']['descricao'] ?? null,
    ]);
}

/* JÁ PAGUEI E NÃO LIBEROU.

   O caminho de escape pra quando o webhook se perde. Qualquer pessoa pode
   chamar pra si mesma — não é privilégio de admin, porque quem precisa disso
   é justamente o cliente comum no pior momento possível: acabou de pagar e
   não recebeu.

   Não confia em nada que venha do navegador: a única coisa que decide é o
   que o Mercado Pago responde sobre as cobranças DESTE usuário. */
if (isset($_GET['conferir'])) {
    if (!$mp['ligado']) json_saida(['erro' => 'A cobrança ainda não abriu.'], 503);

    trava('conferir', 6, 300);

    try {
        $r = mp_reconciliar((int) $quem['usuario_id']);
    } catch (Throwable $e) {
        json_saida(['erro' => $e->getMessage()], 502);
    }

    json_saida([
        'ok'        => true,
        'pendentes' => $r['pendentes'],
        'liberados' => $r['liberados'],
        'recado'    => $r['liberados'] > 0
            ? 'Achei o seu pagamento e liberei. Recarregue a página.'
            : ($r['pendentes'] > 0
                ? 'Encontrei cobrança aberta, mas o Mercado Pago ainda não confirmou o pagamento. '
                  . 'Se você pagou por Pix agora, espere um minuto e tente de novo.'
                : 'Não achei nenhuma cobrança sua dos últimos 30 dias esperando confirmação.'),
    ]);
}

/* O DIAGNÓSTICO DA CREDENCIAL. Só admin, porque conta de qual conta do
   Mercado Pago o site está falando. */
if (isset($_GET['diagnostico'])) {
    $ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
    $ad->execute([(int) $quem['usuario_id']]);
    if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

    json_saida(mp_diagnostico());
}

/* PERGUNTAR SEM COMPRAR.

   O painel precisa saber se existe cobrança antes de desenhar botão nenhum,
   e perguntar isso não pode criar uma cobrança. Por isso a consulta é um
   caminho separado, e ele responde 200 mesmo com a cobrança desligada. */
if (isset($_GET['estado'])) {
    $acesso = acesso_do_usuario((int) $quem['usuario_id']);

    $st = db()->query("SELECT slug, nome, preco_centavos, periodo FROM planos
                        WHERE preco_centavos > 0 ORDER BY preco_centavos");

    $st2 = db()->prepare("SELECT MAX(valido_ate) FROM assinaturas
                           WHERE usuario_id = ? AND status = 'ativa'");
    $st2->execute([(int) $quem['usuario_id']]);

    $ate = $st2->fetchColumn() ?: null;

    /* QUANTOS DIAS FALTAM, CONTADO NO SERVIDOR.

       O relógio da máquina de quem assiste pode estar torto, e "faltam 3
       dias" calculado lá viraria aviso na hora errada — cedo demais é
       barulho, tarde demais é a pessoa perdendo o Pro no meio da live sem
       nunca ter sido avisada. */
    $dias = null;
    if ($ate && $acesso['ativo']) {
        $dias = (int) floor((strtotime($ate) - time()) / 86400);
    }

    /* O painel precisa saber, senão ele mostraria "você está no grátis" pra
       quem tem tudo liberado — e a pessoa iria pagar por engano. */
    $beta = usuario_beta((int) $quem['usuario_id']);

    json_saida([
        'ligado'     => $mp['ligado'],
        'modo'       => $mp['modo'],
        'beta'       => $beta,
        'plano'      => $acesso['ativo'] ? $acesso['plano'] : 'gratis',
        'valido_ate' => $ate,
        'dias'       => $dias,
        'cortesia'   => !empty($acesso['cortesia']),
        'planos'     => $st->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

/* A CHAVE GERAL DA COBRANÇA.

   Enquanto estiver desligada, a estrutura inteira pode estar no ar sendo
   testada sem que exista jeito de alguém ser cobrado por acidente. Ligar é
   uma linha no config.php, e é a ÚLTIMA coisa a fazer — depois do teste de
   ponta a ponta passar. */
if (!$mp['ligado']) {
    json_saida([
        'erro'   => 'A cobrança ainda não abriu. Por enquanto está tudo liberado.',
        'ligado' => false,
    ], 503);
}

$slug  = (string) ($_GET['plano'] ?? 'pro');
$plano = mp_plano_por_slug($slug);

if (!$plano || (int) $plano['preco_centavos'] <= 0) {
    json_saida(['erro' => 'Esse plano não existe ou não é pago.'], 404);
}

$codigo = (string) ($_GET['cupom'] ?? '');

try {
    $r = mp_criar_cobranca((int) $quem['usuario_id'], $plano, $codigo);
} catch (Throwable $e) {
    json_saida(['erro' => $e->getMessage()], 502);
}

if (($r['url'] ?? '') === '') {
    json_saida(['erro' => 'O Mercado Pago não devolveu o link do checkout.'], 502);
}

json_saida([
    'url'   => $r['url'],
    'modo'  => $mp['modo'],
    'cupom' => $r['cupom'],
    'plano' => [
        'nome'     => $plano['nome'],
        'centavos' => (int) $plano['preco_centavos'],
        'cobrado'  => (int) $r['centavos'],
    ],
]);
