<?php
/**
 * O chat mexendo na música.
 *
 * Quem chama é a fonte do OBS, com a chave do painel. O cargo de quem pediu
 * vem junto e é conferido AQUI: a fonte já barra por conta dela, mas quem
 * decide o que é permitido não pode ser a parte que roda no navegador de
 * quem transmite — ela é editável.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/spotify.php';

cors();

const CARGOS_ORDEM = ['chat' => 0, 'sub' => 1, 'vip' => 2, 'mod' => 3, 'supermod' => 4, 'dono' => 5];

$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $st = db()->prepare('SELECT musica_cargo FROM usuarios WHERE id = ?');
    $st->execute([$uid]);

    $ped = db()->prepare(
        'SELECT quem, faixa, onde, UNIX_TIMESTAMP(criado_em) AS quando
           FROM musica_pedidos WHERE usuario_id = ? ORDER BY id DESC LIMIT 20'
    );
    $ped->execute([$uid]);

    json_saida([
        'cargo'   => (string) ($st->fetchColumn() ?: 'sub'),
        'pedidos' => $ped->fetchAll(),
    ]);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');
trava('musica', 90, 60);

/* ---------- configurar (só o painel) ---------- */
if ($acao === 'cargo') {
    $c = (string) ($d['cargo'] ?? 'sub');
    if (!isset(CARGOS_ORDEM[$c])) $c = 'sub';
    db()->prepare('UPDATE usuarios SET musica_cargo = ? WHERE id = ?')->execute([$c, $uid]);
    json_saida(['ok' => true, 'cargo' => $c]);
}

/* ---------- daqui pra baixo é comando do chat ---------- */
$cargo = (string) ($d['cargo'] ?? 'chat');
if (!isset(CARGOS_ORDEM[$cargo])) $cargo = 'chat';
$pediu = mb_substr(trim((string) ($d['quem'] ?? 'alguém')), 0, 64);

function exige(string $minimo, string $cargo): void
{
    if (CARGOS_ORDEM[$cargo] < CARGOS_ORDEM[$minimo]) {
        json_saida(['ok' => false, 'erro' => 'Sem permissão pra isso.'], 403);
    }
}

if ($acao === 'pular') {
    exige('mod', $cargo);
    json_saida(sp_pular($uid));
}

if ($acao === 'curtir') {
    exige('mod', $cargo);
    json_saida(sp_curtir($uid));
}

if ($acao === 'pedir' || $acao === 'fila') {
    /* De qual cargo pra cima o chat pode pedir é escolha de quem transmite. */
    $st = db()->prepare('SELECT musica_cargo FROM usuarios WHERE id = ?');
    $st->execute([$uid]);
    exige((string) ($st->fetchColumn() ?: 'sub'), $cargo);

    /* Um por pessoa a cada dois minutos. Sem isso, uma pessoa sozinha entope
       a playlist inteira antes de alguém perceber. Mod pra cima passa direto:
       quem modera não precisa ser moderado. */
    if (CARGOS_ORDEM[$cargo] < CARGOS_ORDEM['mod']) {
        $r = db()->prepare(
            'SELECT COUNT(*) FROM musica_pedidos
              WHERE usuario_id = ? AND quem = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
        );
        $r->execute([$uid, $pediu]);
        if ((int) $r->fetchColumn() > 0) {
            json_saida(['ok' => false, 'erro' => 'Espere um pouco antes de pedir outra.']);
        }
    }

    $faixa = sp_buscar($uid, (string) ($d['texto'] ?? ''));
    if (!$faixa) json_saida(['ok' => false, 'erro' => 'Não achei essa música no Spotify.']);

    $r = ($acao === 'fila') ? sp_fila($uid, $faixa['uri']) : sp_playlist_por($uid, $faixa['uri']);
    if (empty($r['ok'])) json_saida($r);

    db()->prepare(
        'INSERT INTO musica_pedidos (usuario_id, quem, faixa, uri, onde) VALUES (?, ?, ?, ?, ?)'
    )->execute([$uid, $pediu, mb_substr($faixa['nome'] . ' — ' . $faixa['artista'], 0, 200),
                $faixa['uri'], $acao === 'fila' ? 'fila' : 'playlist']);

    json_saida(['ok' => true, 'faixa' => $faixa['nome'], 'artista' => $faixa['artista']]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
