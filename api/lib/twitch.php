<?php
/**
 * Conversa com a Twitch.
 *
 * O refresh token nunca sai daqui. O painel e a ponte falam com este servidor,
 * e este servidor fala com a Twitch — assim o token não passa por URL nenhuma,
 * que é onde tudo vaza.
 */
require_once __DIR__ . '/db.php';

const TW_AUTORIZAR = 'https://id.twitch.tv/oauth2/authorize';
const TW_TOKEN     = 'https://id.twitch.tv/oauth2/token';
const TW_HELIX     = 'https://api.twitch.tv/helix';

/** O que pedimos ao streamer. Só o necessário — escopo a mais é dívida. */
const TW_ESCOPOS = [
    'channel:manage:predictions',   // criar e resolver Palpites
    'channel:manage:broadcast',     // mudar título e categoria
];

function tw_url_login(string $estado): string
{
    $c = cfg()['twitch'];
    return TW_AUTORIZAR . '?' . http_build_query([
        'client_id'     => $c['client_id'],
        'redirect_uri'  => $c['redirect_uri'],
        'response_type' => 'code',
        'scope'         => implode(' ', TW_ESCOPOS),
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
        $t['access_token'],
        $t['refresh_token'] ?? null,
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
