<?php
/**
 * Login da Twitch.
 *
 * Sem ?code: manda o streamer para a Twitch.
 * Com ?code: troca pelos tokens, cria o usuário e manda a chave do painel
 *            pro site. Guardamos só o hash dela — se perder, é só entrar de
 *            novo, que o aparelho ganha outra sem derrubar os demais.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/twitch.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/acesso.php';

/* O COOKIE DA SESSÃO COM AS TRÊS TRANCAS.

   Esta sessão guarda o 'state' do OAuth, que é o que impede alguém de te
   fazer entrar numa conta que não é sua. Com os padrões do PHP ele sai sem
   marca nenhuma: viajaria em http se alguém forçasse, o JavaScript da página
   conseguiria ler, e ele seria mandado junto em requisição vinda de outro
   site.

   'Lax' e não 'Strict' de propósito: a volta da Twitch é uma navegação vinda
   de fora, e com Strict o cookie não viria junto — o state não bateria e o
   login falharia sempre. */
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
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
    /* A Twitch volta com ?error quando a pessoa clica em Cancelar. Sem isto o
       login recomeçava, e a pessoa caía de novo na tela que acabou de
       recusar. O texto que veio no endereço não é mostrado: qualquer um
       escreveria o que quisesse numa página com o nome do site. */
    if (isset($_GET['error']) || isset($_GET['erro'])) {
        pagina('Login cancelado', '<h1>Login cancelado</h1><p>Nada foi mudado na sua conta.</p>'
            . '<p><a href="entrar.php">Entrar com a Twitch</a></p>');
    }
    $_SESSION['estado_oauth'] = chave_nova(16);
    $_SESSION['entrar_bot'] = isset($_GET['bot']);
    header('Location: ' . tw_url_login($_SESSION['estado_oauth'], isset($_GET['bot']) ? TW_ESCOPOS_BOT : TW_ESCOPOS));
    exit;
}

// ---------- volta ----------
// O state impede que alguém te faça entrar numa conta que não é sua.
if (empty($_GET['state']) || empty($_SESSION['estado_oauth'])
    || !hash_equals($_SESSION['estado_oauth'], $_GET['state'])) {
    pagina('Erro', '<h1>Login não confere</h1><p>Comece de novo por <a href="entrar.php">aqui</a>.</p>');
}
unset($_SESSION['estado_oauth']);
$ehBot = !empty($_SESSION['entrar_bot']);
unset($_SESSION['entrar_bot']);

/* ---------- a conta do bot ----------

   Ela não vira conta do site: só autoriza o aplicativo a falar como ela.
   Nada é guardado — a Twitch lembra da autorização, e o token do aplicativo
   basta daqui pra frente. */
if ($ehBot) {
    try {
        $tokens = tw_trocar_codigo($_GET['code']);
        [$http, $eu] = tw_http('GET', TW_HELIX . '/users', [
            'Authorization: Bearer ' . $tokens['access_token'],
            'Client-Id: ' . cfg()['twitch']['client_id'],
        ]);
        $conta = $eu['data'][0] ?? null;
        if ($http !== 200 || !$conta) throw new RuntimeException('Não consegui ler a conta na Twitch.');
    } catch (Throwable $e) {
        pagina('Erro', '<h1>Não deu certo</h1><p>' . htmlspecialchars(erro_publico($e)) . '</p>');
    }

    $nome = htmlspecialchars((string) $conta['login']);
    $esperado = (string) (cfg()['twitch_bot']['user_id'] ?? '');
    if ($esperado === '') {
        pagina('Bot', '<h1>Conta autorizada</h1><p>A conta <b>' . $nome . '</b> autorizou o ZocaController a falar no chat.</p>'
            . '<p>Pra ela virar o bot, ponha esta linha no config.php e envie o arquivo de novo:</p>'
            . '<code>' . htmlspecialchars("'twitch_bot' => ['user_id' => '" . $conta['id'] . "'],") . '</code>');
    }
    if ((string) $conta['id'] !== $esperado) {
        pagina('Bot', '<h1>Conta errada</h1><p>Você entrou como <b>' . $nome . '</b>, que não é a conta do bot. '
            . 'Saia da Twitch e entre de novo com a conta do bot.</p>');
    }
    pagina('Bot', '<h1>Bot pronto</h1><p>A conta <b>' . $nome . '</b> já fala nos canais que liberaram o bot.</p>');
}

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
    $hash  = hash_chave($chave);

    /* Antes de gravar: depois do INSERT, conta nova e conta que voltou ficam
       iguais, e a boas-vindas cairia em todo login. */
    $ja = db()->prepare('SELECT 1 FROM usuarios WHERE twitch_user_id = ?');
    $ja->execute([$perfil['id']]);
    $primeiraVez = !$ja->fetchColumn();

    /* Nome e foto vêm de graça nesta resposta e ficam guardados: desenhar
       um feed pedindo o perfil de cada autor à Twitch a cada visita bate no
       limite deles num site que funcione. */
    try {
        db()->prepare(
            'INSERT INTO usuarios (twitch_user_id, login, email, chave_painel, nome_exibicao, foto)
                  VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE login = VALUES(login),
                  nome_exibicao = VALUES(nome_exibicao), foto = VALUES(foto)'
        )->execute([
            $perfil['id'],
            $perfil['login'],
            $perfil['email'] ?? null,
            $hash,
            $perfil['display_name'] ?? null,
            $perfil['profile_image_url'] ?? null,
        ]);
    } catch (Throwable $e) {
        /* Colunas novas: entrar não pode quebrar em quem ainda não rodou o SQL. */
        db()->prepare(
            'INSERT INTO usuarios (twitch_user_id, login, email, chave_painel)
                  VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE login = VALUES(login)'
        )->execute([
            $perfil['id'],
            $perfil['login'],
            $perfil['email'] ?? null,
            $hash,
        ]);
    }

    $st = db()->prepare('SELECT id FROM usuarios WHERE twitch_user_id = ?');
    $st->execute([$perfil['id']]);
    $usuario_id = (int) $st->fetchColumn();

    /* UMA CHAVE POR APARELHO.

       Conta nova usa a chave como principal. Quem volta ganha uma chave só
       deste aparelho, e as outras continuam valendo: entrar pelo celular não
       pode derrubar o computador nem a ponte no OBS. Sem o SQL 052, vale o
       jeito antigo, que troca a principal. */
    if (!$primeiraVez) {
        try {
            chave_de_aparelho_nova($usuario_id, $hash, aparelho_nome((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        } catch (PDOException $e) {
            db()->prepare('UPDATE usuarios SET chave_painel = ? WHERE id = ?')->execute([$hash, $usuario_id]);
        }
    }

    if ($primeiraVez) {
        require_once __DIR__ . '/lib/notificacoes.php';
        notifica($usuario_id, 'boas-vindas',
            'Bem-vindo ao ZocaController! Comece criando a sua primeira overlay.',
            '#/overlays', null, 'boas-vindas');
    }

    tw_guardar($usuario_id, $tokens);

} catch (Throwable $e) {
    pagina('Erro', '<h1>Não deu certo</h1><p>' . htmlspecialchars(erro_publico($e)) . '</p>'
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
