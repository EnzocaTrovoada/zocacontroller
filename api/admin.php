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
        'SELECT u.id, u.login, u.criado_em, u.visto_em, u.admin,
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
            'plano'      => $plano,
            'overlays'   => (int) $u['overlays'],
            'perfis_max' => $u['perfis_max'] === null ? null : (int) $u['perfis_max'],
            'recursos'   => $u['recursos'] ? json_decode((string) $u['recursos'], true) : null,
            'vale'       => recursos_do_usuario((int) $u['id'], $plano),
        ];
    }

    json_saida(['eu' => $uid, 'usuarios' => $lista, 'padrao' => [
        'gratis' => recursos_do_plano('gratis'),
        'pro'    => recursos_do_plano('pro'),
    ]]);
}

/* ---------- mudar um ---------- */
$d = corpo_json();
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
