<?php
/**
 * A música pelo Last.fm.
 *
 * POR QUE ELE EXISTE AO LADO DO SPOTIFY: o app do Spotify em modo de
 * desenvolvimento atende cinco contas, cada uma escrita à mão na lista do
 * painel deles, e todo o resto leva 403. Para um site com gente chegando
 * sozinha isso é inviável.
 *
 * O Last.fm não tem lista de permissão nem OAuth — a leitura de "o que fulano
 * está ouvindo" é pública. Basta a chave do servidor e o nome de usuário.
 *
 * O QUE ELE NÃO DÁ: duração da faixa nem progresso. O endpoint de faixas
 * recentes não traz esses campos, então a barra de progresso não tem como
 * andar aqui — e mostrar uma barra parada seria pior do que não mostrar.
 */
require_once __DIR__ . '/db.php';

const LF_API = 'https://ws.audioscrobbler.com/2.0/';

function lf_chave(): string
{
    $c = cfg()['lastfm']['api_key'] ?? '';
    if ($c === '') throw new RuntimeException('O Last.fm não está configurado neste servidor.');
    return $c;
}

function lf_http(array $params): array
{
    $params['api_key'] = lf_chave();
    $params['format']  = 'json';

    $ch = curl_init(LF_API . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        /* O Last.fm devolve 403 sem User-Agent identificável. */
        CURLOPT_USERAGENT      => 'ZocaController/1.0 (+https://zocahop.com)',
    ]);
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, json_decode((string) $r, true)];
}

/** A maior capa que vier, ou vazio. */
function lf_capa(array $t): string
{
    $imgs = $t['image'] ?? [];
    if (!is_array($imgs)) return '';
    $melhor = '';
    foreach ($imgs as $i) {
        $u = (string) ($i['#text'] ?? '');
        /* O Last.fm devolve a lista do menor pro maior, então o último não
           vazio é o maior. Vazio é comum: nem toda faixa tem capa lá. */
        if ($u !== '') $melhor = $u;
    }
    return $melhor;
}

/**
 * O que a pessoa está ouvindo agora, no formato que o overlay já entende.
 *
 * Devolve null quando não há nada tocando — e "a última que ela ouviu" conta
 * como nada: sem o nowplaying, o overlay ficaria mostrando pra sempre a
 * música que tocou de manhã.
 */
function lf_tocando(int $usuario_id, int $maxIdade = 10): ?array
{
    $st = db()->prepare(
        'SELECT json, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade
           FROM spotify_cache WHERE usuario_id = ?'
    );
    $st->execute([$usuario_id]);
    $linha = $st->fetch();
    $guardado = ($linha && $linha['json']) ? json_decode($linha['json'], true) : null;
    if ($linha && (int) $linha['idade'] < $maxIdade) return $guardado;

    $u = db()->prepare('SELECT lastfm_user FROM usuarios WHERE id = ?');
    $u->execute([$usuario_id]);
    $nome = (string) ($u->fetchColumn() ?: '');
    if ($nome === '') return null;

    [$http, $d] = lf_http([
        'method' => 'user.getrecenttracks',
        'user'   => $nome,
        'limit'  => 1,
    ]);
    /* Falha não apaga o que já estava na tela: só não renova. */
    if ($http !== 200 || !isset($d['recenttracks'])) return $guardado;

    $tracks = $d['recenttracks']['track'] ?? [];
    /* Com limit=1 o Last.fm às vezes devolve um objeto em vez de lista. */
    if (isset($tracks['name'])) $tracks = [$tracks];
    $t = $tracks[0] ?? null;

    $musica = null;
    /* nowplaying é o que separa "ouvindo agora" de "ouviu por último". Sem
       ele, a faixa da manhã ficaria na tela a live inteira. */
    if (is_array($t) && (($t['@attr']['nowplaying'] ?? '') === 'true')) {
        $musica = [
            'id'      => (string) ($t['mbid'] ?? ''),
            'nome'    => (string) ($t['name'] ?? ''),
            'artista' => (string) ($t['artist']['#text'] ?? $t['artist']['name'] ?? ''),
            'album'   => (string) ($t['album']['#text'] ?? ''),
            'capa'    => lf_capa($t),
            /* Zero de propósito: o Last.fm não diz quanto dura nem onde está.
               O desenhista esconde a barra quando a duração é zero. */
            'dura'    => 0,
            'em'      => 0,
            'tocando' => true,
        ];
    }

    db()->prepare(
        'INSERT INTO spotify_cache (usuario_id, json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE json = VALUES(json), atualizado_em = NOW()'
    )->execute([$usuario_id, $musica ? json_encode($musica, JSON_UNESCAPED_UNICODE) : null]);

    return $musica;
}

/** Confere se o nome existe, e já diz o que está tocando. */
function lf_conferir(string $nome): array
{
    $nome = trim($nome);
    if ($nome === '') return ['ok' => false, 'erro' => 'Escreva o seu nome de usuário do Last.fm.'];

    [$http, $d] = lf_http(['method' => 'user.getinfo', 'user' => $nome]);

    if ($http === 404 || (isset($d['error']) && (int) $d['error'] === 6)) {
        return ['ok' => false, 'erro' => 'Não achei esse usuário no Last.fm. Confira se escreveu igual ao do perfil.'];
    }
    if ($http !== 200 || empty($d['user'])) {
        return ['ok' => false, 'erro' => 'O Last.fm não respondeu agora (' . $http . ').'];
    }
    return ['ok' => true, 'nome' => (string) $d['user']['name']];
}
