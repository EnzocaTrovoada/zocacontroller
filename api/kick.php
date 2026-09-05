<?php
/**
 * Conectar o Kick.
 *
 * Duas portas no mesmo arquivo, como o spotify.php: sem ?code= é o começo
 * (manda pro Kick), com ?code= é a volta.
 *
 * O Kick usa OAuth 2.1, e nele o PKCE é obrigatório mesmo pra quem tem
 * segredo. O verificador nasce na ida, fica guardado no banco, e a volta o
 * consome — ele nunca passa pelo navegador de ninguém, que é o ponto inteiro
 * do PKCE.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/kick.php';

/* ---------- a volta do Kick ---------- */
if (isset($_GET['code']) || isset($_GET['error'])) {
    $pagina = function (string $titulo, string $corpo): void {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($titulo) . '</title>'
           . '<body style="font:15px system-ui;background:#0E1411;color:#E4EDE7;padding:40px">'
           . $corpo . '</body>';
        exit;
    };

    if (isset($_GET['error'])) {
        $pagina('Não deu certo', '<h1>Você não autorizou</h1><p>Sem a permissão do Kick não dá '
            . 'pra receber os avisos de lá. Pode tentar de novo pelo painel.</p>');
    }

    /* O state é assinado e vale pouco tempo. Sem ele, um link forjado poderia
       amarrar a conta de Kick de um estranho na sua. */
    $alvo = link_verificar((string) ($_GET['state'] ?? ''));
    if (!$alvo || !ctype_digit((string) $alvo)) {
        $pagina('Não confere', '<h1>Esse link não vale mais</h1><p>Ele expira depois de '
            . 'alguns minutos. Volte ao painel e clique em conectar de novo.</p>');
    }
    $usuario_id = (int) $alvo;

    /* Pega E QUEIMA: uma volta de OAuth vale uma vez só. */
    $verificador = kick_consome_verificador($usuario_id);
    if (!$verificador) {
        $pagina('Não confere', '<h1>Esse pedido expirou</h1><p>Comece de novo pelo painel.</p>');
    }

    try {
        $c = kick_cfg();
        /* Form-urlencoded e com o secret NO CORPO: é o que a doc do Kick pede.
           O PKCE aqui soma ao secret, não substitui. */
        [$http, $t] = kick_http('POST', KICK_TOKEN,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type'    => 'authorization_code',
                'code'          => (string) $_GET['code'],
                'client_id'     => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'redirect_uri'  => kick_redirect(),
                'code_verifier' => $verificador,
            ]));

        if ($http !== 200 || empty($t['access_token'])) {
            throw new RuntimeException('O Kick recusou a troca do código.');
        }
        kick_guardar($usuario_id, $t);

        /* Quem é essa conta do lado de lá. Sem o id do canal o webhook não tem
           como saber de quem é o evento que chegar. */
        $eu = kick_eu($usuario_id);
        if ($eu && !empty($eu['user_id'])) {
            db()->prepare('UPDATE kick SET kick_id = ?, canal = ? WHERE usuario_id = ?')
                ->execute([
                    (string) $eu['user_id'],
                    mb_substr((string) ($eu['name'] ?? $eu['username'] ?? ''), 0, 64),
                    $usuario_id,
                ]);
        }

        $r = kick_assinar($usuario_id);
    } catch (Throwable $e) {
        $pagina('Não deu certo', '<h1>Não deu certo</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>');
    }

    $hub = rtrim(cfg()['hub'] ?? 'https://mods.zocahop.com/', '/');
    $aviso = empty($r['ok'])
        ? '<p style="color:#FF8A6B">A conta ligou, mas os avisos não: '
          . htmlspecialchars(implode('; ', $r['erros'] ?? ['motivo desconhecido']))
          . '. Confira se os webhooks estão ligados no app do Kick.</p>'
        : '<p>Follows e assinaturas do Kick agora contam junto com os da Twitch.</p>';

    $pagina('Pronto', '<h1>Kick conectado</h1>' . $aviso
        . '<p><a style="color:#3DD47F" href="' . htmlspecialchars($hub) . '/#/overlays">Voltar</a></p>');
}

/* ---------- daqui pra baixo precisa da chave do painel ---------- */
cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = db()->prepare('SELECT kick_id, canal FROM kick WHERE usuario_id = ? AND token IS NOT NULL');
    $st->execute([$uid]);
    $l = $st->fetch();

    $as = db()->prepare('SELECT tipo FROM kick_assinaturas WHERE usuario_id = ?');
    $as->execute([$uid]);

    /* O verificador nasce AGORA, junto do link: se nascesse só na volta não
       teria com o que comparar, e se nascesse antes do clique cada visita à
       tela sobrescreveria o anterior. */
    $verificador = kick_novo_verificador($uid);

    json_saida([
        'ligado'  => (bool) $l,
        'canal'   => $l ? (string) $l['canal'] : null,
        'avisos'  => $as->fetchAll(PDO::FETCH_COLUMN),
        'entrar'  => KICK_AUTORIZA . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => kick_cfg()['client_id'],
            'redirect_uri'          => kick_redirect(),
            'scope'                 => KICK_ESCOPOS,
            'code_challenge'        => kick_desafio($verificador),
            'code_challenge_method' => 'S256',
            'state'                 => link_assinar((string) $uid, 600),
        ]),
    ]);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');

/* Religar os avisos sem precisar reconectar a conta. Serve pra quando o Kick
   desliga o app sozinho — a doc diz que ele desinscreve quem falha por mais
   de um dia, e nesse caso a conta continua ligada mas nada mais chega. */
if ($acao === 'religar') {
    json_saida(kick_assinar($uid));
}

if ($acao === 'desligar') {
    db()->prepare('DELETE FROM kick_assinaturas WHERE usuario_id = ?')->execute([$uid]);
    db()->prepare('DELETE FROM kick WHERE usuario_id = ?')->execute([$uid]);
    json_saida(['ok' => true]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
