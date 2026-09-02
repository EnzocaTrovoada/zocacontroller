<?php
/**
 * A fila de comandos dos mods.
 *
 * POST — um mod (ou o painel) deixa um pedido
 * GET  — a ponte vem buscar o que ainda não entregou
 *
 * A ponte puxa; o servidor nunca empurra. É o que permite tudo isso rodar em
 * hospedagem compartilhada, sem WebSocket do lado do servidor.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = quem_chama();

/** O que existe. O que não está aqui não existe — negar por padrão. */
const PERMITIDAS = [
    'mute'   => 'audio',
    'som'    => 'audio',   // muta uma fonte específica pelo nome
    'panico' => 'audio',
    'cena'   => 'cena',
    'camera' => 'cena',
    'replay' => 'cena',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    trava('comando', 40, 60);

    $d    = corpo_json();
    $acao = strtolower(trim((string) ($d['acao'] ?? '')));
    $arg  = trim((string) ($d['argumento'] ?? ''));

    if (!isset(PERMITIDAS[$acao])) {
        json_saida(['erro' => 'Esse comando não existe.'], 400);
    }
    exige_poder($quem, PERMITIDAS[$acao]);

    // Sair do pânico é do dono da voz, nunca de quem está assistindo.
    if ($acao === 'panico' && ($d['sair'] ?? false) && $quem['tipo'] !== 'painel') {
        json_saida(['erro' => 'Só o streamer sai do pânico.'], 403);
    }

    if (mb_strlen($arg) > 200) {
        json_saida(['erro' => 'Argumento longo demais.'], 400);
    }

    db()->prepare(
        'INSERT INTO fila_comandos (usuario_id, acao, argumento, quem) VALUES (?, ?, ?, ?)'
    )->execute([$quem['usuario_id'], $acao, $arg ?: null, $quem['nome']]);

    json_saida(['ok' => true]);
}

// ---------- a ponte busca ----------
if ($quem['tipo'] !== 'painel') {
    json_saida(['erro' => 'Só a ponte busca comandos.'], 403);
}

$pdo = db();
$st = $pdo->prepare(
    'SELECT id, acao, argumento, quem
       FROM fila_comandos
      WHERE usuario_id = ? AND entregue_em IS NULL
        AND criado_em > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
      ORDER BY id LIMIT 20'
);
$st->execute([$quem['usuario_id']]);
$lista = $st->fetchAll();

if ($lista) {
    $ids = array_column($lista, 'id');
    $pdo->prepare(
        'UPDATE fila_comandos SET entregue_em = NOW()
          WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
    )->execute($ids);
}

// Pedido velho não vale: se a ponte estava fora do ar, ninguém quer que a
// cena mude sozinha dez minutos depois.
$pdo->prepare(
    'UPDATE fila_comandos SET entregue_em = NOW()
      WHERE usuario_id = ? AND entregue_em IS NULL
        AND criado_em <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
)->execute([$quem['usuario_id']]);

json_saida(['comandos' => $lista]);
