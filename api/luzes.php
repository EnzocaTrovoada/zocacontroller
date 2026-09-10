<?php
/**
 * As luzes: conectar, escolher aparelho, criar cena e obedecer o chat.
 *
 * GET               — catálogo de marcas, contas conectadas e cenas
 * POST acao=salvar  — guarda a credencial de uma marca (testando antes)
 * POST acao=...     — aparelhos, remover, testar, cena_salvar, cena_apagar
 * POST acao=chat    — a ponte manda o que o chat digitou
 *
 * A credencial entra e nunca mais sai: o GET devolve quais marcas estão
 * conectadas, jamais o token.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/luzes.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

function luz_cenas(int $uid): array
{
    $st = db()->prepare('SELECT id, palavra, cor, brilho, ms, cargo FROM luzes_cenas WHERE usuario_id = ? ORDER BY palavra');
    $st->execute([$uid]);
    return array_map(fn($c) => [
        'id'      => (int) $c['id'],
        'palavra' => (string) $c['palavra'],
        'cor'     => $c['cor'],
        'brilho'  => $c['brilho'] === null ? null : (int) $c['brilho'],
        'ms'      => (int) $c['ms'],
        'cargo'   => (string) $c['cargo'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_saida([
        'marcas' => luz_catalogo(),
        'contas' => luz_contas($uid),
        'cenas'  => luz_cenas($uid),
        'cores'  => LUZ_CORES,
    ]);
}

$d    = corpo_json();
$acao = (string) ($d['acao'] ?? '');

/* ---------- o chat pediu ---------- */
if ($acao === 'chat') {
    $ordem = luz_entender($uid, (string) ($d['texto'] ?? ''));
    if (!$ordem) json_saida(['ok' => false, 'erro' => 'não conheço essa cor nem essa cena'], 200);

    /* O cargo da CENA manda quando ela tem um; sem cena, vale o mínimo do
       comando, que a ponte já conferiu antes de chegar aqui. */
    if ($ordem['cargo'] !== null) {
        $tem = array_search((string) ($d['cargo'] ?? 'chat'), LUZ_CARGOS, true);
        $pede = array_search($ordem['cargo'], LUZ_CARGOS, true);
        if ($tem === false || $pede === false || $tem < $pede) {
            json_saida(['ok' => false, 'erro' => 'essa cena não é pro seu cargo'], 200);
        }
    }

    json_saida(luz_aplicar($uid, $ordem));
}

/* ---------- conectar uma marca ---------- */
if ($acao === 'salvar') {
    $id = (string) ($d['driver'] ?? '');
    $drivers = luz_drivers();
    if (!isset($drivers[$id])) json_saida(['erro' => 'Marca desconhecida.'], 400);

    $cfg = [];
    foreach ($drivers[$id]['campos'] ?? [] as $campo) {
        $cfg[$campo['chave']] = mb_substr(trim((string) ($d[$campo['chave']] ?? '')), 0, 400);
    }

    $r = ($drivers[$id]['testar'])($cfg);
    if (empty($r['ok'])) json_saida(['erro' => (string) ($r['erro'] ?? 'Não consegui conectar.')], 400);

    /* Nasce com tudo escolhido: quem acabou de conectar quer que funcione,
       não abrir outra tela pra marcar caixinha. */
    $todos = array_map(fn($a) => (string) $a['id'], $r['aparelhos']);

    db()->prepare(
        'INSERT INTO luzes_contas (usuario_id, driver, config, aparelhos, ligado)
              VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE config = VALUES(config), aparelhos = VALUES(aparelhos), ligado = 1'
    )->execute([$uid, $id, json_encode($cfg), json_encode($todos)]);

    json_saida(['ok' => true, 'aparelhos' => $r['aparelhos'], 'contas' => luz_contas($uid)]);
}

/* ---------- quais aparelhos o chat controla ---------- */
if ($acao === 'aparelhos') {
    $id = (string) ($d['driver'] ?? '');
    $lista = array_values(array_filter(array_map(
        fn($x) => mb_substr((string) $x, 0, 80),
        is_array($d['aparelhos'] ?? null) ? $d['aparelhos'] : []
    )));

    db()->prepare('UPDATE luzes_contas SET aparelhos = ? WHERE usuario_id = ? AND driver = ?')
        ->execute([json_encode($lista), $uid, $id]);
    json_saida(['ok' => true, 'contas' => luz_contas($uid)]);
}

/* ---------- ver a lista de novo, com a credencial já guardada ---------- */
if ($acao === 'reler') {
    $id = (string) ($d['driver'] ?? '');
    $drivers = luz_drivers();
    if (!isset($drivers[$id])) json_saida(['erro' => 'Marca desconhecida.'], 400);

    $st = db()->prepare('SELECT config FROM luzes_contas WHERE usuario_id = ? AND driver = ?');
    $st->execute([$uid, $id]);
    $cfg = json_decode((string) ($st->fetchColumn() ?: '{}'), true) ?: [];

    $r = ($drivers[$id]['testar'])($cfg);
    if (empty($r['ok'])) json_saida(['erro' => (string) ($r['erro'] ?? 'Não consegui falar com a marca.')], 400);
    json_saida(['ok' => true, 'aparelhos' => $r['aparelhos']]);
}

if ($acao === 'remover') {
    db()->prepare('DELETE FROM luzes_contas WHERE usuario_id = ? AND driver = ?')
        ->execute([$uid, (string) ($d['driver'] ?? '')]);
    json_saida(['ok' => true, 'contas' => luz_contas($uid)]);
}

if ($acao === 'testar') {
    $ordem = luz_entender($uid, (string) ($d['texto'] ?? 'vermelho'));
    if (!$ordem) json_saida(['erro' => 'Não conheço essa cor nem essa cena.'], 400);
    json_saida(luz_aplicar($uid, $ordem));
}

/* ---------- cenas ---------- */
if ($acao === 'cena_salvar') {
    $palavra = mb_strtolower(preg_replace('/[^\p{L}\p{N}_-]/u', '', (string) ($d['palavra'] ?? '')));
    $palavra = mb_substr($palavra, 0, 32);
    if ($palavra === '') json_saida(['erro' => 'A cena precisa de uma palavra.'], 400);

    $cor = strtoupper(trim((string) ($d['cor'] ?? '')));
    if ($cor !== '' && !preg_match('/^#[0-9A-F]{6}$/', $cor)) json_saida(['erro' => 'Cor inválida.'], 400);

    $st = db()->prepare('SELECT COUNT(*) FROM luzes_cenas WHERE usuario_id = ?');
    $st->execute([$uid]);
    if ((int) $st->fetchColumn() >= 40) json_saida(['erro' => 'Você já tem 40 cenas.'], 400);

    $brilho = $d['brilho'] === null || $d['brilho'] === '' ? null : max(1, min(100, (int) $d['brilho']));
    $cargo  = in_array((string) ($d['cargo'] ?? ''), LUZ_CARGOS, true) ? (string) $d['cargo'] : 'mod';

    db()->prepare(
        'INSERT INTO luzes_cenas (usuario_id, palavra, cor, brilho, ms, cargo) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE cor = VALUES(cor), brilho = VALUES(brilho), ms = VALUES(ms), cargo = VALUES(cargo)'
    )->execute([$uid, $palavra, $cor ?: null, $brilho, max(0, min(60000, (int) ($d['ms'] ?? 0))), $cargo]);

    json_saida(['ok' => true, 'cenas' => luz_cenas($uid)]);
}

if ($acao === 'cena_apagar') {
    db()->prepare('DELETE FROM luzes_cenas WHERE usuario_id = ? AND id = ?')
        ->execute([$uid, (int) ($d['id'] ?? 0)]);
    json_saida(['ok' => true, 'cenas' => luz_cenas($uid)]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
