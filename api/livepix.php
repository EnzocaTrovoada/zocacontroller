<?php
/**
 * Doação pelo LivePix.
 *
 * Duas coisas moram aqui, separadas pelo método: um GET com ?k= é o LivePix
 * avisando; o resto é o streamer configurando.
 *
 * O ponto que decide o desenho: o LivePix NÃO ASSINA os avisos dele. Não
 * existe HMAC, não existe segredo no cabeçalho. Então qualquer um que
 * descubra a URL consegue mandar um aviso falso.
 *
 * Por isso o aviso, sozinho, não vale nada aqui. Ele é só um empurrão que diz
 * "olha o pagamento tal". Quem confirma valor e existência é uma consulta
 * autenticada de volta ao LivePix. Aviso forjado morre nessa consulta.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/seguranca.php';

const LP_OAUTH = 'https://oauth.livepix.gg/oauth2/token';
const LP_API   = 'https://api.livepix.gg/v2';

function lp_http(string $metodo, string $url, array $cab = [], $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $cab,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($corpo) ? $corpo : json_encode($corpo));
    }
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, json_decode((string) $r, true)];
}

function lp_token(array $conta): ?string
{
    [$http, $r] = lp_http('POST', LP_OAUTH,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $conta['client_id'],
            'client_secret' => $conta['client_secret'],
            'scope'         => 'payments:read webhooks',
        ])
    );
    return ($http === 200 && !empty($r['access_token'])) ? $r['access_token'] : null;
}

/* ------------------------------------------------------------------ *
 *  Vindo do LivePix
 * ------------------------------------------------------------------ */

$segredo = (string) ($_GET['k'] ?? '');

if ($segredo !== '') {
    // Responder rápido e sempre 200: erro nosso não pode fazer o LivePix
    // ficar reenviando o mesmo aviso por 24 horas.
    http_response_code(200);
    echo 'ok';
    responder_e_continuar();

    if (!preg_match('/^[A-Za-z0-9_-]{20,48}$/', $segredo)) {
        exit;
    }

    $st = db()->prepare('SELECT * FROM livepix WHERE segredo_url = ? AND ligado = 1 LIMIT 1');
    $st->execute([$segredo]);
    $conta = $st->fetch();
    if (!$conta) {
        exit;
    }

    $aviso = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($aviso['event'] ?? '') !== 'payment') {
        exit;
    }

    $pagamento_id = (string) ($aviso['resource']['id'] ?? '');
    if ($pagamento_id === '') {
        exit;
    }

    // A parte que importa: perguntar ao LivePix se este pagamento existe
    // mesmo e quanto foi. É isto que torna um aviso forjado inofensivo.
    $token = lp_token($conta);
    if (!$token) {
        exit;
    }

    [$http, $p] = lp_http('GET', LP_API . '/payments/' . rawurlencode($pagamento_id),
        ['Authorization: Bearer ' . $token]);

    if ($http !== 200 || empty($p['data'])) {
        exit;
    }
    $pag = $p['data'];

    // O LivePix trabalha em centavos.
    $reais = round(((float) ($pag['amount'] ?? 0)) / 100, 2);
    if ($reais <= 0) {
        exit;
    }

    db()->prepare('UPDATE livepix SET ultimo_ok = NOW() WHERE usuario_id = ?')
        ->execute([$conta['usuario_id']]);

    require_once __DIR__ . '/lib/subathon-somar.php';
    subathon_somar((int) $conta['usuario_id'], [
        'tipo'       => 'real',
        'quantidade' => $reais,
        'chave'      => 'livepix:' . $pagamento_id,
        'quem'       => (string) ($pag['username'] ?? $pag['name'] ?? 'alguém'),
        'detalhe'    => 'R$ ' . number_format($reais, 2, ',', '.'),
    ]);
    exit;
}

/* ------------------------------------------------------------------ *
 *  Vindo do streamer
 * ------------------------------------------------------------------ */

require_once __DIR__ . '/lib/acesso.php';
cors();
$quem = exige_painel();

$st = db()->prepare('SELECT client_id, segredo_url, ligado, ultimo_ok FROM livepix WHERE usuario_id = ?');
$st->execute([$quem['usuario_id']]);
$conta = $st->fetch();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_saida([
        'configurado' => (bool) $conta,
        'ligado'      => $conta ? (bool) $conta['ligado'] : false,
        'client_id'   => $conta['client_id'] ?? '',
        'ultimo_ok'   => $conta['ultimo_ok'] ?? null,
        // Nunca devolve o segredo do cliente. A URL sim, porque ela precisa
        // ser colada no painel do LivePix.
        'url'         => $conta
            ? rtrim(cfg()['api_base'] ?? '', '/') . '/livepix.php?k=' . $conta['segredo_url']
            : null,
    ]);
}

trava('livepix', 20, 300);
$d = corpo_json();

if (!empty($d['desligar'])) {
    db()->prepare('UPDATE livepix SET ligado = 0 WHERE usuario_id = ?')->execute([$quem['usuario_id']]);
    json_saida(['ok' => true, 'ligado' => false]);
}

$cid = trim((string) ($d['client_id'] ?? ''));
$sec = trim((string) ($d['client_secret'] ?? ''));
if ($cid === '' || $sec === '') {
    json_saida(['erro' => 'Cole o Client ID e o Client Secret da sua aplicação no LivePix.'], 400);
}

// Testa antes de guardar: melhor recusar agora do que a pessoa descobrir
// numa live que nunca funcionou.
if (!lp_token(['client_id' => $cid, 'client_secret' => $sec])) {
    json_saida(['erro' => 'O LivePix não aceitou esse par. Confira se copiou os dois certinho.'], 400);
}

$url_segredo = $conta['segredo_url'] ?? chave_nova(24);

db()->prepare(
    'INSERT INTO livepix (usuario_id, client_id, client_secret, segredo_url, ligado)
          VALUES (?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE client_id = VALUES(client_id),
          client_secret = VALUES(client_secret), ligado = 1'
)->execute([$quem['usuario_id'], $cid, $sec, $url_segredo]);

json_saida([
    'ok'  => true,
    'url' => rtrim(cfg()['api_base'] ?? '', '/') . '/livepix.php?k=' . $url_segredo,
]);
