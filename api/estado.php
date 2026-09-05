<?php
/**
 * O relé de estado.
 *
 * POST  — a ponte publica o que está acontecendo (precisa da chave do painel)
 * GET   — o painel ou um mod lê
 *
 * Uma linha por streamer, sempre sobrescrita: é foto do agora, não histórico.
 * Guardar histórico aqui seria juntar dado sem ninguém ter pedido.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = quem_chama();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($quem['tipo'] !== 'painel') {
        json_saida(['erro' => 'Só a ponte publica estado.'], 403);
    }

    $estado = corpo_json();
    if (!$estado) {
        json_saida(['erro' => 'Corpo vazio.'], 400);
    }

    // Só o que o painel dos mods precisa ver. Nada de nome de arquivo,
    // caminho ou configuração de fonte — isso não sobe.
    $limpo = [
        'cena'   => (string) ($estado['cena'] ?? ''),
        'mudo'   => (bool)   ($estado['mudo'] ?? false),
        'mic'    => (string) ($estado['mic'] ?? ''),
        'nivel'  => (float)  ($estado['nivel'] ?? 0),
        'panico' => (bool)   ($estado['panico'] ?? false),
        'chat'   => (bool)   ($estado['chat'] ?? false),
        'olho'   => isset($estado['olho']) && is_array($estado['olho']) ? [
            'ligado'   => (bool) ($estado['olho']['ligado'] ?? false),
            'pronto'   => (bool) ($estado['olho']['pronto'] ?? false),
            'vendo'    => isset($estado['olho']['vendo']) ? (bool) $estado['olho']['vendo'] : null,
            'quebrado' => (string) ($estado['olho']['quebrado'] ?? ''),
        ] : null,
        // Binario, e nao o numero do medidor: quem ve de fora precisa saber se
        // esta saindo som, e isso muda devagar o bastante para caber aqui.
        'falando' => (bool) ($estado['falando'] ?? false),
        'cenas'  => array_slice(array_map('strval', (array) ($estado['cenas'] ?? [])), 0, 40),
        // As fontes da cena no ar, com o olhinho de cada uma.
        'fontes' => array_slice(array_values(array_map(
            fn($f) => [
                'nome'    => (string) ($f['nome'] ?? ''),
                'id'      => (int) ($f['id'] ?? 0),
                'visivel' => (bool) ($f['visivel'] ?? false),
            ],
            (array) ($estado['fontes'] ?? [])
        )), 0, 40),
        'sons'   => array_slice(array_values(array_map(
            fn($s) => ['nome' => (string) ($s['nome'] ?? ''), 'mudo' => (bool) ($s['mudo'] ?? false)],
            (array) ($estado['sons'] ?? [])
        )), 0, 30),
    ];

    db()->prepare(
        'INSERT INTO estado_ao_vivo (usuario_id, estado) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE estado = VALUES(estado), atualizado_em = NOW()'
    )->execute([$quem['usuario_id'], json_encode($limpo, JSON_UNESCAPED_UNICODE)]);

    /* DE VOLTA PRA PONTE: O QUE ACONTECEU FORA DO CHAT.

       Sub e bits a ponte vê sozinha, porque chegam pelo próprio chat. Follow
       e doação não passam por lá — chegam por webhook, do lado do servidor.
       Sem este caminho de volta, gatilho de follow nunca dispararia.

       Vai de carona nesta requisição de propósito: a ponte já publica estado
       a cada poucos segundos, então isto não é uma consulta a mais. E vem só
       o que é mais novo que o último id que ela disse ter visto — na primeira
       vez ela manda -1 e recebe só a marca, sem disparar o dia inteiro. */
    $desde = (int) ($estado['eventos_desde'] ?? -1);
    $volta = ['ok' => true];

    if ($desde >= 0) {
        $ev = db()->prepare(
            'SELECT id, tipo, quem, quantidade,
                    UNIX_TIMESTAMP(criado_em) AS quando
               FROM eventos
              WHERE usuario_id = ? AND id > ? AND tipo IN (?, ?)
              ORDER BY id LIMIT 20'
        );
        /* Só o que o chat não vê. Sub e bits chegariam em dobro: a ponte já
           os viu passar no próprio chat. */
        $ev->execute([$quem['usuario_id'], $desde, 'follow', 'real']);
        $volta['eventos'] = array_map(fn($e) => [
            'id'         => (int) $e['id'],
            'tipo'       => (string) $e['tipo'],
            'quem'       => (string) ($e['quem'] ?: 'alguém'),
            'quantidade' => (int) $e['quantidade'],
        ], $ev->fetchAll());
    }

    /* A marca de onde a ponte deve começar a olhar, pra ela não precisar
       adivinhar nem receber o histórico inteiro na primeira vez. */
    $ultimo = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM eventos WHERE usuario_id = ?');
    $ultimo->execute([$quem['usuario_id']]);
    $volta['eventos_ate'] = (int) $ultimo->fetchColumn();

    json_saida($volta);
}

// ---------- leitura ----------
$st = db()->prepare(
    'SELECT estado, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade
       FROM estado_ao_vivo WHERE usuario_id = ?'
);
$st->execute([$quem['usuario_id']]);
$linha = $st->fetch();

if (!$linha) {
    json_saida(['ligada' => false, 'motivo' => 'A ponte ainda não publicou nada.']);
}

// Estado velho é pior que estado nenhum: mostra o passado como se fosse agora.
// A ponte publica junto com o ciclo da espera longa, que é de 15 s.
// O teto tem que caber isso mais folga, senão pisca "sem sinal" à toa.
$idade = (int) $linha['idade'];
json_saida([
    'ligada' => $idade <= 30,
    'idade'  => $idade,
    'estado' => json_decode($linha['estado'], true),
    'eu'     => ['nome' => $quem['nome'], 'tipo' => $quem['tipo'], 'pode' => $quem['pode']],
]);
