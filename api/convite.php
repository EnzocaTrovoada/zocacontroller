<?php
/**
 * Convites de moderador.
 *
 * Um link por pessoa, de propósito: link compartilhado não dá para tirar de um
 * só quando alguém sai da equipe.
 *
 * GET                        → lista os convites (sem os links, que não voltam)
 * POST {nome, pode:{...}}    → cria e mostra o link UMA vez
 * POST {revogar: id}         → derruba na hora
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';

cors();
$quem = exige_painel();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = db()->prepare(
        'SELECT id, nome, pode_cena, pode_audio, pode_canal, criado_em, ultimo_uso, revogado
           FROM convites_mod WHERE usuario_id = ? ORDER BY id DESC'
    );
    $st->execute([$quem['usuario_id']]);

    json_saida(['convites' => array_map(fn($c) => [
        'id'         => (int) $c['id'],
        'nome'       => $c['nome'],
        'pode'       => [
            'cena'  => (bool) $c['pode_cena'],
            'audio' => (bool) $c['pode_audio'],
            'canal' => (bool) $c['pode_canal'],
        ],
        'criado_em'  => $c['criado_em'],
        'ultimo_uso' => $c['ultimo_uso'],
        'revogado'   => (bool) $c['revogado'],
    ], $st->fetchAll())]);
}

$d = corpo_json();

// ---------- revogar ----------
if (isset($d['revogar'])) {
    db()->prepare('UPDATE convites_mod SET revogado = 1 WHERE id = ? AND usuario_id = ?')
        ->execute([(int) $d['revogar'], $quem['usuario_id']]);
    json_saida(['ok' => true]);
}

// ---------- criar ----------
$nome = trim((string) ($d['nome'] ?? ''));
if ($nome === '' || mb_strlen($nome) > 64) {
    json_saida(['erro' => 'Escreva o nome do moderador.'], 400);
}

$st = db()->prepare('SELECT COUNT(*) FROM convites_mod WHERE usuario_id = ? AND revogado = 0');
$st->execute([$quem['usuario_id']]);
if ((int) $st->fetchColumn() >= 30) {
    json_saida(['erro' => 'Você já tem 30 convites ativos. Revogue algum antes.'], 400);
}

$token = chave_nova(24);
$pode  = (array) ($d['pode'] ?? []);

db()->prepare(
    'INSERT INTO convites_mod (usuario_id, nome, token_hash, pode_cena, pode_audio, pode_canal)
          VALUES (?, ?, ?, ?, ?, ?)'
)->execute([
    $quem['usuario_id'], $nome, hash('sha256', $token),
    !empty($pode['cena'])  ? 1 : 0,
    !empty($pode['audio']) ? 1 : 0,
    !empty($pode['canal']) ? 1 : 0,
]);

// Guardamos só o hash: esta é a única vez que o link existe em texto.
json_saida([
    'ok'    => true,
    'nome'  => $nome,
    'link'  => 'https://mods.zocahop.com/mods.html#' . $token,
    'aviso' => 'Copie agora e mande para ' . $nome . '. Este link não aparece de novo.',
]);
