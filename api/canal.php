<?php
/**
 * Título e categoria da live.
 *
 * GET  ?buscar=league of legnds  → a busca da própria Twitch já perdoa erro
 *                                   de digitação, então não existe lista de
 *                                   apelidos para manter aqui.
 * GET                            → como está o canal agora, e os últimos
 *                                   pares de título+categoria já usados
 * POST {titulo, categoria_id, tags} → muda
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/twitch.php';

cors();
$quem = quem_chama();
exige_poder($quem, 'canal');

/**
 * As tags do jeito que a Twitch aceita, ou o pedido é recusado inteiro.
 *
 * As regras são dela: no máximo 10, cada uma de 1 a 25 caracteres, só
 * letra e número — espaço não entra, e é justamente por isso que dá pra
 * guardar a lista separada por espaço sem inventar separador.
 *
 * Limpar em vez de recusar é de propósito: quem digita "so chill" quer
 * duas tags, não um erro. O que não dá pra salvar some, e o resto vai.
 */
function canal_tags_limpas(array $tags): array
{
    $fora = [];
    $jaTem = [];

    foreach ($tags as $t) {
        /* A acentuação some junto com o resto: a Twitch não aceita "ç" numa
           tag, e mandar "Portugues" é melhor do que perder a tag inteira. */
        $t = (string) $t;
        if (function_exists('iconv')) {
            $sem = @iconv('UTF-8', 'ASCII//TRANSLIT', $t);
            if ($sem !== false) $t = $sem;
        }
        $t = preg_replace('/[^A-Za-z0-9]/', '', $t);
        if ($t === '') continue;

        $t = mb_substr($t, 0, 25);
        $chave = mb_strtolower($t);
        if (isset($jaTem[$chave])) continue;

        $jaTem[$chave] = true;
        $fora[] = $t;
        if (count($fora) >= 10) break;
    }
    return $fora;
}

/**
 * Anota o par título+categoria que está no ar.
 *
 * Anotar na LEITURA, e não só quando alguém troca por aqui, é o que faz a
 * lista existir pra quem sempre trocou o título pela própria Twitch: na
 * primeira vez que o painel abre, o que estiver lá já entra.
 *
 * Nada aqui pode derrubar a resposta — sem o SQL 057 o canal funciona.
 */
function canal_anota(int $uid, string $titulo, string $cat, string $catId, array $tags = []): void
{
    $titulo = trim($titulo);
    if ($titulo === '') return;

    $tagsTexto = mb_substr(implode(' ', canal_tags_limpas($tags)), 0, 300);

    try {
        db()->prepare(
            'INSERT INTO canal_usados (usuario_id, titulo, categoria, categoria_id, tags)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE usado_em = NOW(), categoria = VALUES(categoria), tags = VALUES(tags)'
        )->execute([$uid, mb_substr($titulo, 0, 160), mb_substr($cat, 0, 120), $catId, $tagsTexto]);
        return;
    } catch (Throwable $e) { /* pode ser só a coluna nova faltando */ }

    /* SEM A COLUNA DAS TAGS, GRAVA O RESTO.
       Quem rodou o 057 antes do 058 ainda ganha a lista de títulos; só as
       tags é que não são lembradas até rodar o 058. */
    try {
        db()->prepare(
            'INSERT INTO canal_usados (usuario_id, titulo, categoria, categoria_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE usado_em = NOW(), categoria = VALUES(categoria)'
        )->execute([$uid, mb_substr($titulo, 0, 160), mb_substr($cat, 0, 120), $catId]);
    } catch (Throwable $e) { /* sem o SQL 057 ninguém tem lista */ }
}

/**
 * As tags que esta pessoa usou da última vez NESTE jogo.
 *
 * É esta a automação: lista curada de tag por jogo é impossível de manter
 * (a Twitch tem dezenas de milhares de categorias) e sempre erraria o que
 * o canal é. O que a pessoa escolheu da última vez naquele jogo acerta,
 * porque foi ela que escolheu.
 */
function canal_tags_do_jogo(int $uid, string $catId): array
{
    if ($catId === '') return [];
    try {
        $st = db()->prepare(
            'SELECT tags FROM canal_usados
              WHERE usuario_id = ? AND categoria_id = ? AND LENGTH(tags) > 0
              ORDER BY usado_em DESC LIMIT 1'
        );
        $st->execute([$uid, $catId]);
        $t = (string) $st->fetchColumn();
        return $t === '' ? [] : preg_split('/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY);
    } catch (Throwable $e) {
        return [];   /* sem o SQL 058, sem memória de tag */
    }
}

/** Os últimos pares usados, e a faxina do resto. */
function canal_usados(int $uid, int $quantos = 8): array
{
    try {
        /* SELECT *: a coluna das tags chegou depois, e pedir ela pelo nome
           faria a lista inteira sumir pra quem ainda não rodou o 058. */
        $st = db()->prepare(
            'SELECT * FROM canal_usados
              WHERE usuario_id = ? ORDER BY usado_em DESC LIMIT ' . max(1, min(20, $quantos))
        );
        $st->execute([$uid]);
        $lista = array_map(function (array $u): array {
            $t = (string) ($u['tags'] ?? '');
            return [
                'titulo'       => (string) $u['titulo'],
                'categoria'    => (string) $u['categoria'],
                'categoria_id' => (string) $u['categoria_id'],
                'tags'         => $t === '' ? [] : preg_split('/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY),
            ];
        }, $st->fetchAll());
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
                    (string) ($c['game_name'] ?? ''), (string) ($c['game_id'] ?? ''),
                    (array) ($c['tags'] ?? []));

        json_saida([
            'titulo'        => $c['title'] ?? '',
            'categoria'     => $c['game_name'] ?? '',
            'categoria_id'  => $c['game_id'] ?? '',
            'idioma'        => $c['broadcaster_language'] ?? '',
            'tags'          => array_values((array) ($c['tags'] ?? [])),
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

    /* AS TAGS QUE COMBINAM COM O JOGO.

       Mandadas na mão, valem elas. Sem mandar nada, trocar de categoria
       traz de volta as que esta pessoa usou da última vez NAQUELE jogo —
       que é a automação: lista de tag pronta por jogo é impossível de
       manter (a Twitch tem dezenas de milhares de categorias) e erraria o
       que o canal é. Quem acerta é a escolha anterior da própria pessoa.

       Lista vazia mandada de propósito limpa as tags: [] é diferente de
       não mandar nada, e sem essa diferença não teria como tirar todas. */
    if (isset($d['tags']) && is_array($d['tags'])) {
        $corpo['tags'] = canal_tags_limpas($d['tags']);
    } elseif (isset($corpo['game_id'])) {
        $doJogo = canal_tags_do_jogo((int) $quem['usuario_id'], $corpo['game_id']);
        if ($doJogo) $corpo['tags'] = $doJogo;
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
       erro que se comete ao vivo.

       DENTRO DO PRÓPRIO try: O PATCH JÁ PASSOU. O título mudou na Twitch
       neste instante. Se esta segunda chamada cair — token renovando,
       limite, oscilação — deixar o erro subir faria a tela dizer que não
       deu, e a pessoa trocaria de novo achando que falhou. Anotar é o
       menos importante que acontece aqui. */
    try {
        [$hc, $rc] = tw_helix($quem['usuario_id'], 'GET', '/channels', ['broadcaster_id' => $bid]);
        if ($hc === 200 && !empty($rc['data'][0])) {
            $c = $rc['data'][0];
            canal_anota((int) $quem['usuario_id'], (string) ($c['title'] ?? ''),
                        (string) ($c['game_name'] ?? ''), (string) ($c['game_id'] ?? ''),
                        (array) ($c['tags'] ?? []));
        }
    } catch (Throwable $e) {
        error_log('[zc] canal: troquei mas não consegui reler: ' . $e->getMessage());
    }

    json_saida([
        'ok'     => true,
        'por'    => $quem['nome'],
        'tags'   => array_values((array) ($corpo['tags'] ?? [])),
        'usados' => canal_usados((int) $quem['usuario_id']),
    ]);

} catch (RuntimeException $e) {
    json_saida(['erro' => erro_publico($e)], 400);
}
