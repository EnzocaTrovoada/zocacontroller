<?php
/**
 * Login da Twitch.
 *
 * Sem ?code: manda o streamer para a Twitch.
 * Com ?code: troca pelos tokens, cria o usuário e mostra a chave do painel
 *            UMA vez. Guardamos só o hash dela — se perder, gera outra.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/twitch.php';
require_once __DIR__ . '/lib/seguranca.php';

session_start();

function pagina(string $titulo, string $miolo): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width,initial-scale=1">',
         '<title>', htmlspecialchars($titulo), ' — ZocaController</title><style>',
         'body{margin:0;padding:8vh 20px;background:#101A20;color:#DCE6EA;',
         'font:16px/1.6 system-ui,sans-serif;-webkit-font-smoothing:antialiased}',
         '.w{max-width:560px;margin:0 auto}',
         'h1{font-size:28px;margin:0 0 16px;letter-spacing:-.02em}',
         'p{color:#93A5B0;margin:0 0 16px}',
         'code{display:block;background:#17222A;border:1px solid #45B8BF;color:#45B8BF;',
         'padding:14px;font:14px ui-monospace,monospace;word-break:break-all;user-select:all;margin:16px 0}',
         '.aviso{border-left:3px solid #D3A244;background:#17222A;padding:14px 16px;color:#93A5B0;font-size:14px}',
         'a{color:#45B8BF}</style></head><body><div class="w">', $miolo, '</div></body></html>';
    exit;
}

// ---------- ida ----------
if (!isset($_GET['code'])) {
    if (isset($_GET['erro'])) {
        pagina('Erro', '<h1>Não deu certo</h1><p>' . htmlspecialchars($_GET['erro']) . '</p>');
    }
    $_SESSION['estado_oauth'] = chave_nova(16);
    header('Location: ' . tw_url_login($_SESSION['estado_oauth']));
    exit;
}

// ---------- volta ----------
// O state impede que alguém te faça entrar numa conta que não é sua.
if (empty($_GET['state']) || empty($_SESSION['estado_oauth'])
    || !hash_equals($_SESSION['estado_oauth'], $_GET['state'])) {
    pagina('Erro', '<h1>Login não confere</h1><p>Comece de novo por <a href="entrar.php">aqui</a>.</p>');
}
unset($_SESSION['estado_oauth']);

try {
    $tokens = tw_trocar_codigo($_GET['code']);

    // Quem entrou? Precisa do id numérico antes de existir usuário no banco.
    [$http, $eu] = tw_http('GET', TW_HELIX . '/users', [
        'Authorization: Bearer ' . $tokens['access_token'],
        'Client-Id: ' . cfg()['twitch']['client_id'],
    ]);
    if ($http !== 200 || empty($eu['data'][0]['id'])) {
        throw new RuntimeException('Não consegui ler seu perfil na Twitch.');
    }
    $perfil = $eu['data'][0];

    $chave = chave_nova(24);

    db()->prepare(
        'INSERT INTO usuarios (twitch_user_id, login, email, chave_painel)
              VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE login = VALUES(login), chave_painel = VALUES(chave_painel)'
    )->execute([
        $perfil['id'],
        $perfil['login'],
        $perfil['email'] ?? null,
        hash('sha256', $chave),
    ]);

    $st = db()->prepare('SELECT id FROM usuarios WHERE twitch_user_id = ?');
    $st->execute([$perfil['id']]);
    $usuario_id = (int) $st->fetchColumn();

    tw_guardar($usuario_id, $tokens);

} catch (Throwable $e) {
    pagina('Erro', '<h1>Não deu certo</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>'
        . '<p><a href="entrar.php">Tentar de novo</a></p>');
}

/*
 * Em vez de mostrar a chave numa pagina sem saida, manda para o hub com ela
 * no # do endereco. O # nunca chega ao servidor, entao a chave nao entra em
 * log nenhum — e la ela ja vem montada dentro de todos os links.
 */
$hub = (cfg()['hub'] ?? 'https://mods.zocahop.com/') . '#' . rawurlencode($chave);
header('Location: ' . $hub);
exit;
