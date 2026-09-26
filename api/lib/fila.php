<?php
/**
 * A fila de comandos que a ponte vem buscar.
 *
 * MORA AQUI PORQUE SÃO DUAS PORTAS PRA MESMA COISA: o painel e o link de
 * moderador entram pelo comando.php; o botão físico entra pelo botao.php.
 * Com a lista escrita nos dois, um dia alguém libera uma ação num lugar e
 * esquece do outro — e a diferença só aparece quando alguém aperta.
 *
 * O servidor nunca empurra: a ponte puxa. É o que permite tudo isto rodar em
 * hospedagem compartilhada, sem WebSocket do lado de cá.
 */
require_once __DIR__ . '/db.php';

/**
 * O que existe, e de que poder cada um precisa.
 *
 * O QUE NÃO ESTÁ AQUI NÃO EXISTE — negar por padrão. Uma ação que chegue com
 * nome desconhecido não é um comando novo; é alguém tentando.
 */
const FILA_PERMITIDAS = [
    'mute'   => 'audio',
    'som'    => 'audio',   // muta uma fonte específica pelo nome
    'panico' => 'audio',
    'cena'   => 'cena',
    'fonte'  => 'cena',    // o olhinho: mostra e esconde na transmissão
    'camera' => 'cena',
    'replay' => 'cena',
    'marcar' => 'cena',    // anota o momento para achar no VOD depois
];

/**
 * Deixa um pedido na fila. A ponte pega no ciclo seguinte.
 *
 * Não confere poder nem quem está pedindo: isso é de quem chama, que é quem
 * sabe se veio do painel, de um mod ou de um botão.
 */
function fila_poe(int $usuario_id, string $acao, ?string $argumento, string $quem): void
{
    db()->prepare(
        'INSERT INTO fila_comandos (usuario_id, acao, argumento, quem) VALUES (?, ?, ?, ?)'
    )->execute([
        $usuario_id,
        $acao,
        ($argumento !== null && $argumento !== '') ? mb_substr($argumento, 0, 200) : null,
        mb_substr($quem, 0, 64),
    ]);
}
