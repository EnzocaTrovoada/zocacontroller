<?php
/**
 * Conversa com a Twitch.
 *
 * O refresh token nunca sai daqui. O painel e a ponte falam com este servidor,
 * e este servidor fala com a Twitch — assim o token não passa por URL nenhuma,
 * que é onde tudo vaza.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cifra.php';

const TW_AUTORIZAR = 'https://id.twitch.tv/oauth2/authorize';
const TW_TOKEN     = 'https://id.twitch.tv/oauth2/token';
const TW_HELIX     = 'https://api.twitch.tv/helix';

/** O que pedimos ao streamer. Só o necessário — escopo a mais é dívida. */
const TW_ESCOPOS = [
    'channel:manage:predictions',   // criar e resolver Palpites
    'channel:manage:broadcast',     // mudar título e categoria
    'moderator:read:followers',     // saber quem seguiu (channel.follow v2)
    'channel:read:subscriptions',   // subs, para as metas
    'bits:read',                    // bits, para as metas
    'user:write:chat',              // comando que responde no chat, pela própria conta
    'channel:bot',                  // deixar o bot do ZocaController falar no canal
    'channel:edit:commercial',      // rodar anuncio sozinho, de tempo em tempo
    'channel:read:ads',             // quanto tempo sem pre-roll o canal ganhou
];

/**
 * As permissoes que esta conta deu, do jeito que a Twitch devolveu.
 *
 * Mora aqui porque e assunto da Twitch, nao do chat: quem precisa saber se
 * uma permissao existe e qualquer parte que va chamar a API dela.
 */
function tw_escopos(int $usuario_id): array
{
    $st = db()->prepare('SELECT tw_escopos FROM usuarios WHERE id = ?');
    $st->execute([$usuario_id]);
    return preg_split('/\s+/', (string) $st->fetchColumn(), -1, PREG_SPLIT_NO_EMPTY);
}

/* O que a conta do BOT autoriza, uma vez só, em entrar.php?bot=1. Com isso
   o token do aplicativo manda mensagem como ela nos canais que deram
   channel:bot — e nenhum token do bot precisa ficar guardado aqui. */
const TW_ESCOPOS_BOT = ['user:bot', 'user:write:chat', 'user:read:chat'];

/**
 * Token do APLICATIVO, não do usuário.
 *
 * Assinatura de EventSub por webhook exige este: com token de usuário a
 * Twitch responde 401. O token do streamer serve para outra coisa — provar
 * que ele autorizou os escopos, o que zera o custo da assinatura.
 *
 * Vale algumas horas, então guardamos e só renovamos quando falta pouco.
 */
function tw_token_do_app(): string
{
    static $cache = null;
    if ($cache && $cache['ate'] > time() + 60) {
        return $cache['token'];
    }

    $c = cfg()['twitch'];
    [$http, $corpo] = tw_http('POST', TW_TOKEN,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type'    => 'client_credentials',
        ])
    );

    if ($http !== 200 || empty($corpo['access_token'])) {
        throw new RuntimeException('A Twitch não deu o token do aplicativo.');
    }

    $cache = [
        'token' => $corpo['access_token'],
        'ate'   => time() + (int) ($corpo['expires_in'] ?? 3600),
    ];
    return $cache['token'];
}

/** Chamada à Helix com o token do aplicativo. */
function tw_helix_app(string $metodo, string $caminho, array $query = [], $corpo = null): array
{
    $cabecalhos = [
        'Authorization: Bearer ' . tw_token_do_app(),
        'Client-Id: ' . cfg()['twitch']['client_id'],
    ];
    if ($corpo !== null) {
        $cabecalhos[] = 'Content-Type: application/json';
    }
    return tw_http($metodo, TW_HELIX . $caminho . ($query ? '?' . http_build_query($query) : ''),
        $cabecalhos, $corpo);
}

function tw_url_login(string $estado, array $escopos = TW_ESCOPOS): string
{
    $c = cfg()['twitch'];
    return TW_AUTORIZAR . '?' . http_build_query([
        'client_id'     => $c['client_id'],
        'redirect_uri'  => $c['redirect_uri'],
        'response_type' => 'code',
        'scope'         => implode(' ', $escopos),
        'state'         => $estado,
        'force_verify'  => 'true',
    ]);
}

/** Chamada crua de HTTP. Devolve [codigo, corpo decodificado]. */
function tw_http(string $metodo, string $url, array $cabecalhos = [], $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $cabecalhos,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($corpo) ? $corpo : json_encode($corpo));
    }

    $resposta = curl_exec($ch);
    if ($resposta === false) {
        $erro = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Não consegui falar com a Twitch: ' . $erro);
    }
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$codigo, json_decode($resposta, true)];
}

/** Troca o código do login pelos tokens. */
function tw_trocar_codigo(string $codigo): array
{
    $c = cfg()['twitch'];
    [$http, $corpo] = tw_http('POST', TW_TOKEN,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'code'          => $codigo,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $c['redirect_uri'],
        ])
    );
    if ($http !== 200 || empty($corpo['access_token'])) {
        throw new RuntimeException('A Twitch recusou o login: ' . ($corpo['message'] ?? "http $http"));
    }
    return $corpo;
}

/** Guarda os tokens do usuário. */
function tw_guardar(int $usuario_id, array $t): void
{
    $st = db()->prepare(
        'UPDATE usuarios
            SET tw_acesso = ?, tw_refresh = ?, tw_expira_em = ?, tw_escopos = ?
          WHERE id = ?'
    );
    $st->execute([
        segredo_guarda($t['access_token']),
        segredo_guarda($t['refresh_token'] ?? null),
        date('Y-m-d H:i:s', time() + (int) ($t['expires_in'] ?? 3600)),
        implode(' ', $t['scope'] ?? []),
        $usuario_id,
    ]);
}

/**
 * Devolve um access token válido, renovando se estiver perto de vencer.
 * A margem de 120 s existe porque a requisição seguinte leva tempo.
 */
function tw_token(int $usuario_id): string
{
    $st = db()->prepare('SELECT tw_acesso, tw_refresh, tw_expira_em FROM usuarios WHERE id = ?');
    $st->execute([$usuario_id]);
    $u = $st->fetch();
    if ($u) {
        $u['tw_acesso']  = segredo_le($u['tw_acesso']);
        $u['tw_refresh'] = segredo_le($u['tw_refresh']);
    }

    if (!$u || !$u['tw_refresh']) {
        throw new RuntimeException('Este canal ainda não entrou com a Twitch.');
    }
    if ($u['tw_acesso'] && strtotime($u['tw_expira_em']) - 120 > time()) {
        return $u['tw_acesso'];
    }

    $c = cfg()['twitch'];
    [$http, $corpo] = tw_http('POST', TW_TOKEN,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $u['tw_refresh'],
        ])
    );

    if ($http !== 200 || empty($corpo['access_token'])) {
        // Refresh recusado quer dizer que o streamer tirou o acesso.
        // Limpar é melhor do que ficar tentando para sempre.
        db()->prepare('UPDATE usuarios SET tw_refresh = NULL, tw_acesso = NULL WHERE id = ?')
            ->execute([$usuario_id]);
        throw new RuntimeException('O acesso à Twitch expirou. Entre de novo.');
    }

    tw_guardar($usuario_id, $corpo);
    return $corpo['access_token'];
}

/**
 * Chamada à Helix já autenticada. Devolve [codigo, corpo].
 * Não lança em erro de API: quem chamou decide o que fazer com o código.
 */
function tw_helix(int $usuario_id, string $metodo, string $caminho, array $query = [], $corpo = null): array
{
    $token = tw_token($usuario_id);

    /* A BARRA NÃO PODE DEPENDER DE QUEM CHAMA.

       TW_HELIX não termina em barra, então caminho sem barra virava
       ".../helixstreams" — um 404 que parece erro da Twitch e é erro nosso.
       Passou despercebido porque a maioria das chamadas escreve "/streams" e
       só as três da contagem escreviam "streams": a contagem automática de
       seguidores, subs e viewers nunca funcionou em conta nenhuma. */
    if ($caminho === '' || $caminho[0] !== '/') $caminho = '/' . $caminho;

    $url = TW_HELIX . $caminho . ($query ? '?' . http_build_query($query) : '');

    $cabecalhos = [
        'Authorization: Bearer ' . $token,
        'Client-Id: ' . cfg()['twitch']['client_id'],
    ];
    if ($corpo !== null) {
        $cabecalhos[] = 'Content-Type: application/json';
    }

    return tw_http($metodo, $url, $cabecalhos, $corpo);
}

/** O id numérico do canal na Twitch, que a Helix exige em quase tudo. */
function tw_broadcaster_id(int $usuario_id): string
{
    $st = db()->prepare('SELECT twitch_user_id FROM usuarios WHERE id = ?');
    $st->execute([$usuario_id]);
    $id = $st->fetchColumn();
    if (!$id) {
        throw new RuntimeException('Canal sem id da Twitch.');
    }
    return (string) $id;
}
