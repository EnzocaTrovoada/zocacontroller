<?php
/**
 * Título e categoria da live.
 *
 * GET  ?buscar=league of legnds  → a busca da própria Twitch já perdoa erro
 *                                   de digitação, então não existe lista de
 *                                   apelidos para manter aqui.
 * GET                            → como está o canal agora, e os últimos
 *                                   pares de título+categoria já usados
 * POST {titulo, categoria_id}    → muda
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/twitch.php';

cors();
$quem = quem_chama();
exige_poder($quem, 'canal');

/**
 * Anota o par título+categoria que está no ar.
 *
 * Anotar na LEITURA, e não só quando alguém troca por aqui, é o que faz a
 * lista existir pra quem sempre trocou o título pela própria Twitch: na
 * primeira vez que o painel abre, o que estiver lá já entra.
 *
 * Nada aqui pode derrubar a resposta — sem o SQL 057 o canal funciona.
 */
function canal_anota(int $uid, string $titulo, string $cat, string $catId): void
{
    $titulo = trim($titulo);
    if ($titulo === '') return;

    try {
        db()->prepare(
            'INSERT INTO canal_usados (usuario_id, titulo, categoria, categoria_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE usado_em = NOW(), categoria = VALUES(categoria)'
        )->execute([$uid, mb_substr($titulo, 0, 160), mb_substr($cat, 0, 120), $catId]);
    } catch (Throwable $e) { /* sem o SQL 057 ninguém tem lista */ }
}

/** Os últimos pares usados, e a faxina do resto. */
function canal_usados(int $uid, int $quantos = 8): array
{
    try {
        $st = db()->prepare(
            'SELECT titulo, categoria, categoria_id FROM canal_usados
              WHERE usuario_id = ? ORDER BY usado_em DESC LIMIT ' . max(1, min(20, $quantos))
        );
        $st->execute([$uid]);
        $lista = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    /* A faxina é na leitura: uma vez por visita ao painel, e não a cada
       troca de título. O efeito é o mesmo e não pesa em quem está ao vivo. */
    try {
        $st = db()->prepare('SELECT usado_em FROM canal_usados WHERE usuario_id = ? ORDER BY usado_em DESC LIMIT 1 OFFSET 30');
        $st->execute([$uid]);
        $corte = $st->fetchColumn();
        if ($corte) {
            db()->prepare('DELETE FROM canal_usados WHERE usuario_id = ? AND usado_em <= ?')->execute([$uid, $corte]);
        }
    } catch (Throwable $e) { /* a lista já saiu */ }

    return $lista;
}

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
        canal_anota((int) $quem['usuario_id'], (string) ($c['title'] ?? ''),
                    (string) ($c['game_name'] ?? ''), (string) ($c['game_id'] ?? ''));

        json_saida([
            'titulo'        => $c['title'] ?? '',
            'categoria'     => $c['game_name'] ?? '',
            'categoria_id'  => $c['game_id'] ?? '',
            'idioma'        => $c['broadcaster_language'] ?? '',
            'usados'        => canal_usados((int) $quem['usuario_id']),
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

    /* LÊ DE VOLTA PRA ANOTAR O PAR INTEIRO.

       Trocar só o título deixaria a categoria de fora, e meio par não
       serve pra reusar depois — é justamente escolher um sem o outro o
       erro que se comete ao vivo. Uma chamada a mais numa ação que já é
       rara e limitada a vinte por minuto. */
    [$hc, $rc] = tw_helix($quem['usuario_id'], 'GET', '/channels', ['broadcaster_id' => $bid]);
    if ($hc === 200 && !empty($rc['data'][0])) {
        $c = $rc['data'][0];
        canal_anota((int) $quem['usuario_id'], (string) ($c['title'] ?? ''),
                    (string) ($c['game_name'] ?? ''), (string) ($c['game_id'] ?? ''));
    }

    json_saida(['ok' => true, 'por' => $quem['nome'], 'usados' => canal_usados((int) $quem['usuario_id'])]);

} catch (RuntimeException $e) {
    json_saida(['erro' => erro_publico($e)], 400);
}
