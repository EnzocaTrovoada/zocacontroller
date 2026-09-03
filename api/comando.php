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
    'fonte'  => 'cena',   // o olhinho: mostra e esconde na transmissão
    'camera' => 'cena',
    'replay' => 'cena',
    'marcar' => 'cena',   // anota o momento para achar no VOD depois
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

/*
 * Marcar PRIMEIRO, ler depois.
 *
 * Antes era o contrario, e entre o ler e o marcar cabia uma segunda leitura
 * pegando os mesmos comandos — dois panicos do mesmo clique. Acontece de
 * verdade quando a ponte reconecta e a espera longa anterior ainda esta
 * pendurada aqui. O UPDATE e atomico: quem marcar o lote leva, o outro nao
 * acha nada.
 */
$lote = bin2hex(random_bytes(10));

$marcar = $pdo->prepare(
    'UPDATE fila_comandos SET entregue_em = NOW(), lote = ?
      WHERE usuario_id = ? AND entregue_em IS NULL
        AND criado_em > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
      ORDER BY id LIMIT 20'
);
$ler = $pdo->prepare(
    'SELECT id, acao, argumento, quem FROM fila_comandos WHERE lote = ? ORDER BY id'
);

/*
 * Espera longa: em vez de a ponte perguntar de tempo em tempo, ela pergunta
 * uma vez e a gente segura a resposta ate aparecer comando. O mod aperta o
 * botao e a coisa acontece em menos de um segundo, e ainda da MENOS
 * requisicao do que perguntar a cada dois segundos.
 *
 * O teto e baixo de proposito: cada espera segura um processo PHP, e em
 * hospedagem compartilhada isso e recurso contado.
 */
$espera = min(20, max(0, (int) ($_GET['esperar'] ?? 0)));
$ate    = microtime(true) + $espera;
set_time_limit($espera + 15);

$lista = [];
do {
    $marcar->execute([$lote, $quem['usuario_id']]);
    if ($marcar->rowCount() > 0) {
        $ler->execute([$lote]);
        $lista = $ler->fetchAll();
        break;
    }
    if (microtime(true) >= $ate) break;
    usleep(350000);
} while (true);

// Fila entregue nao serve mais para nada depois de um dia.
if (random_int(1, 200) === 1) {
    $pdo->exec('DELETE FROM fila_comandos WHERE entregue_em < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}

// Pedido velho não vale: se a ponte estava fora do ar, ninguém quer que a
// cena mude sozinha dez minutos depois.
$pdo->prepare(
    'UPDATE fila_comandos SET entregue_em = NOW()
      WHERE usuario_id = ? AND entregue_em IS NULL
        AND criado_em <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
)->execute([$quem['usuario_id']]);

json_saida(['comandos' => $lista]);
