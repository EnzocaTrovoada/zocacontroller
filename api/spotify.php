<?php
/**
 * Conectar o Spotify.
 *
 * Duas portas no mesmo arquivo, como o entrar.php faz com a Twitch: sem
 * ?code= é o começo (manda pro Spotify), com ?code= é a volta.
 *
 * O token nunca chega no navegador. Quem fala com o Spotify é o servidor, e o
 * overlay só pergunta "o que está tocando" — assim a chave não aparece em
 * print, em VOD, nem no inspecionar elemento de quem estiver assistindo.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/spotify.php';

/* ---------- a volta do Spotify ---------- */
if (isset($_GET['code']) || isset($_GET['error'])) {
    $pagina = function (string $titulo, string $corpo): void {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($titulo) . '</title>'
           . '<body style="font:15px system-ui;background:#0E1411;color:#E4EDE7;padding:40px">'
           . $corpo . '</body>';
        exit;
    };

    if (isset($_GET['error'])) {
        $pagina('Não deu certo', '<h1>Você não autorizou</h1><p>Sem a permissão do Spotify '
            . 'não dá pra saber o que está tocando. Pode tentar de novo pelo painel.</p>');
    }

    /* O state é assinado e vale pouco tempo. Sem ele, um link forjado poderia
       amarrar a conta de Spotify de um estranho na sua. */
    $alvo = link_verificar((string) ($_GET['state'] ?? ''));
    if (!$alvo || !ctype_digit((string) $alvo)) {
        $pagina('Não confere', '<h1>Esse link não vale mais</h1><p>Ele expira depois de '
            . 'alguns minutos. Volte ao painel e clique em conectar de novo.</p>');
    }
    $usuario_id = (int) $alvo;

    try {
        $a = sp_cfg();
        [$http, $t] = sp_http('POST', SP_TOKEN, [
            'Authorization: Basic ' . base64_encode($a['client_id'] . ':' . $a['client_secret']),
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => (string) $_GET['code'],
            'redirect_uri' => sp_redirect(),
        ]));

        if ($http !== 200 || empty($t['access_token'])) {
            throw new RuntimeException('O Spotify recusou a troca do código.');
        }
        sp_guardar($usuario_id, $t);
    } catch (Throwable $e) {
        $pagina('Não deu certo', '<h1>Não deu certo</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>');
    }

    $hub = rtrim(cfg()['hub'] ?? 'https://mods.zocahop.com/', '/');
    $pagina('Pronto', '<h1>Spotify conectado</h1>'
        . '<p>Pode fechar esta aba e voltar pro painel.</p>'
        . '<p><a style="color:#3DD47F" href="' . htmlspecialchars($hub) . '/#/overlays">Voltar</a></p>');
}

/* ---------- daqui pra baixo precisa da chave do painel ---------- */
cors();
$quem = exige_painel();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = db()->prepare('SELECT 1 FROM spotify WHERE usuario_id = ?');
    $st->execute([$quem['usuario_id']]);
    $ligado = (bool) $st->fetchColumn();

    json_saida([
        'ligado'  => $ligado,
        'tocando' => $ligado ? sp_tocando((int) $quem['usuario_id']) : null,
        /* O link de conectar sai daqui já assinado: a página não sabe montar
           um state válido, e nem deveria. */
        'entrar'  => SP_AUTORIZA . '?' . http_build_query([
            'client_id'     => sp_cfg()['client_id'],
            'response_type' => 'code',
            'redirect_uri'  => sp_redirect(),
            'scope'         => SP_ESCOPOS,
            'state'         => link_assinar((string) $quem['usuario_id'], 600),
            'show_dialog'   => 'true',
        ]),
    ]);
}

$d = corpo_json();
if (($d['acao'] ?? '') === 'desligar') {
    db()->prepare('DELETE FROM spotify WHERE usuario_id = ?')->execute([$quem['usuario_id']]);
    db()->prepare('DELETE FROM spotify_cache WHERE usuario_id = ?')->execute([$quem['usuario_id']]);
    json_saida(['ok' => true]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
