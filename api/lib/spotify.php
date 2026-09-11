<?php
/**
 * A música que está tocando.
 *
 * O token do Spotify dá acesso à conta de quem transmite, então ele NUNCA sai
 * daqui: o overlay pergunta a música pro nosso servidor, e o servidor é quem
 * fala com o Spotify. Se fosse o navegador a perguntar, a chave estaria em
 * todo print, em todo VOD e no inspecionar elemento de qualquer um.
 */
require_once __DIR__ . '/db.php';

const SP_AUTORIZA = 'https://accounts.spotify.com/authorize';
const SP_TOKEN    = 'https://accounts.spotify.com/api/token';
const SP_API      = 'https://api.spotify.com/v1';

/* O que a gente pede. Cada linha existe por um comando:
   ler o que toca, pular, curtir, e mexer em playlist.

   PULAR E ENFILEIRAR SÓ FUNCIONAM COM SPOTIFY PREMIUM — os endpoints de
   player devolvem 403 em conta grátis, e isso é regra do Spotify, não nossa.
   Curtir e playlist funcionam em qualquer conta. */
/* O E-MAIL ENTRA POR UM MOTIVO CHATO E ESPECIFICO.

   O app esta em modo de desenvolvimento no Spotify, e nesse modo eles
   atendem CINCO contas, escritas a mao na lista do painel deles. Todo mundo
   fora da lista recebe 403 em qualquer chamada.

   Sem o e-mail, cadastrar alguem na lista exige perguntar por fora, um a um.
   Com ele, o painel de administracao mostra a lista pronta pra colar.

   Isto nao aumenta o limite de cinco — so tira o atrito de usar os cinco. O
   que acaba com o limite e a extensao de cota, que se pede no painel deles. */
const SP_ESCOPOS = 'user-read-email user-read-currently-playing user-read-playback-state '
                 . 'user-modify-playback-state user-library-modify '
                 . 'playlist-modify-public playlist-modify-private playlist-read-private';

function sp_cfg(): array
{
    $c = cfg()['spotify'] ?? [];
    if (empty($c['client_id']) || empty($c['client_secret'])) {
        throw new RuntimeException('O Spotify não está configurado neste servidor.');
    }
    return $c;
}

function sp_redirect(): string
{
    return api_base() . '/spotify.php';
}

function sp_http(string $metodo, string $url, array $cabecalhos = [], $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $cabecalhos,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);

    $resposta = curl_exec($ch);
    $codigo   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, json_decode((string) $resposta, true)];
}

function sp_guardar(int $usuario_id, array $t): void
{
    /* Na renovação o Spotify às vezes NÃO manda refresh_token novo: quer dizer
       "continua valendo o mesmo". Sobrescrever com vazio desconectaria a
       pessoa sozinho, umas horas depois, sem explicação nenhuma. */
    $temNovo = !empty($t['refresh_token']);

    $sql = $temNovo
        ? 'INSERT INTO spotify (usuario_id, access_token, refresh_token, expira_em)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
           ON DUPLICATE KEY UPDATE access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token), expira_em = VALUES(expira_em)'
        : 'INSERT INTO spotify (usuario_id, access_token, refresh_token, expira_em)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
           ON DUPLICATE KEY UPDATE access_token = VALUES(access_token),
                expira_em = VALUES(expira_em)';

    db()->prepare($sql)->execute([
        $usuario_id,
        (string) $t['access_token'],
        (string) ($t['refresh_token'] ?? ''),
        (int) ($t['expires_in'] ?? 3600),
    ]);
}

/**
 * Anota o e-mail da conta que acabou de conectar.
 *
 * É o que permite cadastrar a pessoa na lista de permissão do app sem ter
 * que perguntar por fora, um a um. Serve pra ISSO e nada mais: não vai pra
 * lugar nenhum, não sai do painel de administração, e some junto com a
 * conexão quando a pessoa desconecta.
 *
 * Falhar aqui não pode derrubar a conexão: quem acabou de autorizar o
 * Spotify quer o Spotify funcionando, e o e-mail é conveniência nossa.
 */
function sp_anota_email(int $usuario_id, string $token): void
{
    try {
        [$http, $eu] = sp_http('GET', 'https://api.spotify.com/v1/me',
            ['Authorization: Bearer ' . $token]);
        if ($http !== 200 || empty($eu['email'])) return;

        db()->prepare('UPDATE spotify SET email = ? WHERE usuario_id = ?')
            ->execute([mb_substr((string) $eu['email'], 0, 160), $usuario_id]);
    } catch (Throwable $e) {
        /* coluna nova ou escopo ainda não autorizado: não é motivo pra
           quebrar uma conexão que deu certo */
    }
}

/** Token válido, renovando se precisar. Null se a pessoa não conectou. */
function sp_token(int $usuario_id): ?string
{
    $st = db()->prepare(
        'SELECT access_token, refresh_token,
                TIMESTAMPDIFF(SECOND, NOW(), expira_em) AS falta
           FROM spotify WHERE usuario_id = ?'
    );
    $st->execute([$usuario_id]);
    $c = $st->fetch();
    if (!$c) return null;

    /* 60 segundos de folga: renovar em cima da hora deixa a consulta seguinte
       falhar por um token que venceu no meio do caminho. */
    if ((int) $c['falta'] > 60) return $c['access_token'];
    if (!$c['refresh_token']) return null;

    $a = sp_cfg();
    [$http, $t] = sp_http('POST', SP_TOKEN, [
        'Authorization: Basic ' . base64_encode($a['client_id'] . ':' . $a['client_secret']),
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $c['refresh_token'],
    ]));

    if ($http !== 200 || empty($t['access_token'])) return null;

    sp_guardar($usuario_id, $t);
    return $t['access_token'];
}

/**
 * O que está tocando, em forma pronta pra desenhar.
 *
 * Null quer dizer "nada tocando", que é diferente de erro: sem nada tocando o
 * overlay some, e com erro ele continua mostrando o que já estava lá. Trocar
 * as duas coisas faria a música piscar toda vez que a internet tossisse.
 */
function sp_tocando(int $usuario_id, int $maxIdade = 5): ?array
{
    $st = db()->prepare(
        'SELECT json, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade
           FROM spotify_cache WHERE usuario_id = ?'
    );
    $st->execute([$usuario_id]);
    $linha = $st->fetch();
    $guardado = ($linha && $linha['json']) ? json_decode($linha['json'], true) : null;

    if ($linha && (int) $linha['idade'] < $maxIdade) return $guardado;

    $token = sp_token($usuario_id);
    if (!$token) return $guardado;

    [$http, $d] = sp_http('GET', SP_API . '/me/player/currently-playing?market=from_token',
        ['Authorization: Bearer ' . $token]);

    /* Erro de verdade não apaga o que já estava: só não renova. */
    if ($http !== 200 && $http !== 204) return $guardado;

    /* 204 é "não tem nada tocando" — resposta boa, não falha. */
    $musica = null;
    if ($http === 200 && !empty($d['item'])) {
        $it = $d['item'];
        $artistas = array_map(function ($a) { return $a['name'] ?? ''; }, $it['artists'] ?? []);
        $capas = $it['album']['images'] ?? [];
        $musica = [
            /* O id vem junto: sem ele nao da pra curtir nem enfileirar o que
               esta tocando, e seria preciso perguntar de novo. */
            'id'      => (string) ($it['id'] ?? ''),
            'nome'    => (string) ($it['name'] ?? ''),
            'artista' => implode(', ', array_filter($artistas)),
            'album'   => (string) ($it['album']['name'] ?? ''),
            /* A do meio: a maior tem 640px e pesaria à toa num overlay que
               desenha isso com 80 pixels de lado. */
            'capa'    => (string) ($capas[1]['url'] ?? $capas[0]['url'] ?? ''),
            'dura'    => (int) ($it['duration_ms'] ?? 0),
            'em'      => (int) ($d['progress_ms'] ?? 0),
            'tocando' => !empty($d['is_playing']),
        ];
    }

    db()->prepare(
        'INSERT INTO spotify_cache (usuario_id, json) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE json = VALUES(json), atualizado_em = NOW()'
    )->execute([$usuario_id, $musica ? json_encode($musica, JSON_UNESCAPED_UNICODE) : null]);

    return $musica;
}

/* ---------------------------------------------------------------------
   O CHAT MEXENDO NA MÚSICA

   Toda função aqui devolve ['ok' => bool, 'erro' => string]. O erro é escrito
   pra ser LIDO NA TELA por quem está transmitindo, e não pra log: "precisa de
   Premium" é a resposta certa pra um 403 de player, e "não achei essa música"
   é a resposta certa pra uma busca vazia.
   --------------------------------------------------------------------- */

function sp_chamar(int $usuario_id, string $metodo, string $caminho, $corpo = null): array
{
    $token = sp_token($usuario_id);
    if (!$token) return [0, null];

    $cab = ['Authorization: Bearer ' . $token];
    if ($corpo !== null) $cab[] = 'Content-Type: application/json';

    return sp_http($metodo, SP_API . $caminho, $cab,
        $corpo === null ? null : json_encode($corpo, JSON_UNESCAPED_UNICODE));
}

/** Traduz o código do Spotify em frase que serve pra pessoa. */
function sp_erro(int $http, string $oQue): string
{
    if ($http === 0)   return 'O Spotify não está conectado. Conecte no painel.';
    if ($http === 401) return 'A conexão com o Spotify venceu. Conecte de novo no painel.';
    if ($http === 403) return $oQue . ' precisa de Spotify Premium.';
    if ($http === 404) return 'Nenhum aparelho tocando. Abra o Spotify e dê play em alguma coisa.';
    if ($http === 429) return 'O Spotify pediu pra esperar um pouco.';
    return 'O Spotify recusou (' . $http . ').';
}

function sp_pular(int $usuario_id): array
{
    [$http] = sp_chamar($usuario_id, 'POST', '/me/player/next', []);
    if ($http >= 200 && $http < 300) return ['ok' => true];
    return ['ok' => false, 'erro' => sp_erro($http, 'Pular música')];
}

/** Curtir o que está tocando. Não precisa de Premium. */
function sp_curtir(int $usuario_id): array
{
    $m = sp_tocando($usuario_id, 0);
    if (!$m || empty($m['id'])) return ['ok' => false, 'erro' => 'Não tem nada tocando agora.'];

    /* O ENDEREÇO MUDOU EM MARÇO DE 2026, E O ANTIGO NÃO AVISA QUE MORREU.

       O Spotify juntou salvar faixa, álbum, podcast e seguir artista num
       endereço só: PUT /me/library, com as URIs na QUERY (não no corpo, ao
       contrário de quase todo o resto da API deles). O antigo PUT /me/tracks
       passou a devolver 403 mesmo com token e escopo certos — 403 que a
       gente traduzia como "o app está em modo de desenvolvimento", mandando
       a pessoa conferir uma coisa que não tinha nada a ver.

       O caminho velho fica de reserva: se um dia o novo responder 404 num
       app antigo, o !like continua funcionando em vez de sumir. */
    $uri = 'spotify:track:' . $m['id'];
    [$http] = sp_chamar($usuario_id, 'PUT', '/me/library?uris=' . rawurlencode($uri), []);

    if ($http === 404 || $http === 405) {
        [$http] = sp_chamar($usuario_id, 'PUT', '/me/tracks', ['ids' => [$m['id']]]);
    }

    if ($http >= 200 && $http < 300) return ['ok' => true, 'faixa' => $m['nome']];
    return ['ok' => false, 'erro' => sp_erro($http, 'Curtir')];
}

/**
 * Acha uma música pelo que a pessoa escreveu.
 *
 * O chat digita "despacito" ou "legião urbana tempo perdido", não uma URI.
 * Pego o primeiro resultado: a busca do Spotify já ordena por relevância, e
 * oferecer uma lista de escolhas no chat seria uma conversa que o chat não
 * tem como ter.
 */
function sp_buscar(int $usuario_id, string $termo): ?array
{
    $termo = trim($termo);
    if ($termo === '') return null;

    /* Link colado também vale: é o jeito mais comum de pedir sem errar. */
    if (preg_match('~(?:spotify\.com/track/|spotify:track:)([A-Za-z0-9]+)~', $termo, $m)) {
        [$http, $d] = sp_chamar($usuario_id, 'GET', '/tracks/' . $m[1]);
        if ($http !== 200 || empty($d['uri'])) return null;
        return ['uri' => $d['uri'], 'nome' => $d['name'] ?? '',
                'artista' => implode(', ', array_map(function ($a) { return $a['name'] ?? ''; }, $d['artists'] ?? []))];
    }

    [$http, $d] = sp_chamar($usuario_id, 'GET',
        '/search?type=track&limit=1&q=' . urlencode(mb_substr($termo, 0, 120)));
    if ($http !== 200 || empty($d['tracks']['items'][0])) return null;

    $it = $d['tracks']['items'][0];
    return [
        'uri'     => $it['uri'],
        'nome'    => $it['name'] ?? '',
        'artista' => implode(', ', array_map(function ($a) { return $a['name'] ?? ''; }, $it['artists'] ?? [])),
    ];
}

/** Põe na fila do que está tocando. Precisa de Premium. */
function sp_fila(int $usuario_id, string $uri): array
{
    [$http] = sp_chamar($usuario_id, 'POST', '/me/player/queue?uri=' . urlencode($uri), []);
    if ($http >= 200 && $http < 300) return ['ok' => true];
    return ['ok' => false, 'erro' => sp_erro($http, 'Pôr na fila')];
}

/**
 * A playlist do chat. Criada uma vez e guardada.
 *
 * Sem guardar o id, cada pedido criaria uma playlist nova na conta da pessoa —
 * e em uma semana ela teria trezentas.
 */
function sp_playlist(int $usuario_id): ?string
{
    $st = db()->prepare('SELECT playlist_id FROM spotify WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    $id = $st->fetchColumn();
    if ($id) return (string) $id;

    [$http, $eu] = sp_chamar($usuario_id, 'GET', '/me');
    if ($http !== 200 || empty($eu['id'])) return null;

    [$http, $pl] = sp_chamar($usuario_id, 'POST', '/users/' . rawurlencode($eu['id']) . '/playlists', [
        'name'        => 'Pedidos do chat',
        'public'      => false,
        'description' => 'Criada pelo ZocaController. O que o seu chat pediu na live.',
    ]);
    if ($http < 200 || $http >= 300 || empty($pl['id'])) return null;

    db()->prepare('UPDATE spotify SET playlist_id = ? WHERE usuario_id = ?')
        ->execute([$pl['id'], $usuario_id]);
    return (string) $pl['id'];
}

/**
 * DE ONDE A MÚSICA ESTÁ SAINDO AGORA.
 *
 * O Spotify chama de "contexto" a playlist, álbum ou artista de onde a faixa
 * toca. Ele vem junto da resposta do que está tocando — mas só com o endereço,
 * sem o nome. Daí a segunda chamada: o chat quer o nome, não a URL da API.
 *
 * Isto NÃO entra no sp_tocando. Aquele roda a cada poucos segundos pra todo
 * overlay aberto; este só roda quando alguém digita o comando.
 */
function sp_playlist_atual(int $usuario_id): array
{
    $token = sp_token($usuario_id);
    if (!$token) return ['ok' => false, 'erro' => sp_erro(0, 'Ver a playlist')];

    [$http, $d] = sp_http('GET', SP_API . '/me/player/currently-playing?market=from_token',
        ['Authorization: Bearer ' . $token]);
    if ($http !== 200 && $http !== 204) {
        return ['ok' => false, 'erro' => sp_erro($http, 'Ver a playlist')];
    }

    $ctx = ($http === 200) ? ($d['context'] ?? null) : null;
    if ($ctx && !empty($ctx['href'])) {
        [$h2, $c] = sp_http('GET', (string) $ctx['href'], ['Authorization: Bearer ' . $token]);
        if ($h2 === 200 && !empty($c['name'])) {
            return [
                'ok'   => true,
                'de'   => 'tocando',
                'tipo' => (string) ($ctx['type'] ?? 'playlist'),
                'nome' => (string) $c['name'],
                'link' => (string) ($ctx['external_urls']['spotify'] ?? ''),
            ];
        }
    }

    /* Sem contexto — faixa solta, ou tocando as curtidas. O que ainda serve
       pro chat é a playlist de pedidos, que é do chat mesmo. */
    $pl = sp_playlist($usuario_id);
    if (!$pl) return ['ok' => false, 'erro' => 'Não está tocando de nenhuma playlist agora.'];
    return [
        'ok'   => true,
        'de'   => 'pedidos',
        'tipo' => 'playlist',
        'nome' => 'Pedidos do chat',
        'link' => 'https://open.spotify.com/playlist/' . $pl,
    ];
}

function sp_playlist_por(int $usuario_id, string $uri): array
{
    $pl = sp_playlist($usuario_id);
    if (!$pl) return ['ok' => false, 'erro' => 'Não consegui criar a playlist do chat.'];

    [$http] = sp_chamar($usuario_id, 'POST', '/playlists/' . rawurlencode($pl) . '/tracks', ['uris' => [$uri]]);
    if ($http >= 200 && $http < 300) return ['ok' => true];
    return ['ok' => false, 'erro' => sp_erro($http, 'Pôr na playlist')];
}

/**
 * O QUE O SPOTIFY RESPONDE AGORA, SEM FILTRO.
 *
 * O sp_tocando engole o código HTTP de propósito: pra ele, falha e "nada
 * tocando" acabam no mesmo lugar, que é não mexer na tela. Isso está certo
 * pro overlay e é péssimo pra descobrir por que não funciona — "conectado e
 * nada tocando" e "o Spotify recusou" viram a mesma frase.
 *
 * Esta função existe só pra tela de diagnóstico e devolve o código cru.
 */
function sp_diagnostico(int $usuario_id): array
{
    $st = db()->prepare('SELECT 1 FROM spotify WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    if (!$st->fetchColumn()) return ['etapa' => 'sem-conta'];

    try {
        $token = sp_token($usuario_id);
    } catch (Throwable $e) {
        return ['etapa' => 'token', 'erro' => $e->getMessage()];
    }
    if (!$token) return ['etapa' => 'token', 'erro' => 'Não consegui renovar o acesso.'];

    /* As duas chamadas, porque elas falham por motivos diferentes: a de
       "tocando agora" é a que o overlay usa, e a de "player" conta se existe
       algum aparelho ativo — que é o que costuma faltar. */
    [$h1, $d1] = sp_http('GET', SP_API . '/me/player/currently-playing',
        ['Authorization: Bearer ' . $token]);
    [$h2, $d2] = sp_http('GET', SP_API . '/me/player',
        ['Authorization: Bearer ' . $token]);

    return [
        'etapa'          => 'ok',
        'http_tocando'   => $h1,
        'tem_item'       => !empty($d1['item']),
        'nome'           => (string) ($d1['item']['name'] ?? ''),
        'http_player'    => $h2,
        'tocando'        => !empty($d2['is_playing']),
        'aparelho'       => (string) ($d2['device']['name'] ?? ''),
        'tipo_aparelho'  => (string) ($d2['device']['type'] ?? ''),
        'privado'        => !empty($d2['device']['is_private_session']),
        'erro_api'       => (string) ($d1['error']['message'] ?? $d2['error']['message'] ?? ''),
    ];
}
