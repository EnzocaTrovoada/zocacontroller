<?php
/**
 * Título e categoria da live.
 *
 * GET  ?buscar=league of legnds  → a busca da própria Twitch já perdoa erro
 *                                   de digitação, então não existe lista de
 *                                   apelidos para manter aqui.
 * GET                            → como está o canal agora
 * POST {titulo, categoria_id}    → muda
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/twitch.php';

cors();
$quem = quem_chama();
exige_poder($quem, 'canal');

try {
    $bid = tw_broadcaster_id($quem['usuario_id']);

    // ---------- buscar categoria ----------
    if (isset($_GET['buscar'])) {
        trava('buscar', 60, 60);

        $termo = trim((string) $_GET['buscar']);
        if (mb_strlen($termo) < 2) {
            json_saida(['categorias' => []]);
        }

        [$http, $r] = tw_helix($quem['usuario_id'], 'GET', '/search/categories',
            ['query' => $termo, 'first' => 12]);

        if ($http !== 200) {
            json_saida(['erro' => 'A Twitch não respondeu a busca.'], 502);
        }

        json_saida(['categorias' => array_map(fn($c) => [
            'id'   => $c['id'],
            'nome' => $c['name'],
            'capa' => str_replace(['{width}', '{height}'], ['52', '72'], $c['box_art_url'] ?? ''),
        ], $r['data'] ?? [])]);
    }

    // ---------- como está agora ----------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        [$http, $r] = tw_helix($quem['usuario_id'], 'GET', '/channels', ['broadcaster_id' => $bid]);
        if ($http !== 200 || empty($r['data'][0])) {
            json_saida(['erro' => 'Não consegui ler o canal.'], 502);
        }
        $c = $r['data'][0];
        json_saida([
            'titulo'        => $c['title'] ?? '',
            'categoria'     => $c['game_name'] ?? '',
            'categoria_id'  => $c['game_id'] ?? '',
            'idioma'        => $c['broadcaster_language'] ?? '',
        ]);
    }

    // ---------- mudar ----------
    trava('mudar_canal', 20, 60);
    $d = corpo_json();
    $corpo = [];

    if (isset($d['titulo'])) {
        $t = trim((string) $d['titulo']);
        if ($t === '')            json_saida(['erro' => 'O título não pode ficar vazio.'], 400);
        if (mb_strlen($t) > 140)  json_saida(['erro' => 'O título passa de 140 caracteres.'], 400);
        $corpo['title'] = $t;
    }
    if (isset($d['categoria_id']) && $d['categoria_id'] !== '') {
        $corpo['game_id'] = (string) $d['categoria_id'];
    }
    if (!$corpo) {
        json_saida(['erro' => 'Nada para mudar.'], 400);
    }

    [$http, $r] = tw_helix($quem['usuario_id'], 'PATCH', '/channels',
        ['broadcaster_id' => $bid], $corpo);

    if ($http !== 204 && $http !== 200) {
        json_saida(['erro' => 'A Twitch recusou: ' . ($r['message'] ?? "http $http")], 502);
    }

    json_saida(['ok' => true, 'por' => $quem['nome']]);

} catch (RuntimeException $e) {
    json_saida(['erro' => $e->getMessage()], 400);
}
