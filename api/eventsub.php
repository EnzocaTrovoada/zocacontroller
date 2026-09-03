<?php
/**
 * EventSub — os avisos que a Twitch manda quando alguém segue, dá sub ou bits.
 *
 * Este arquivo faz duas coisas, e dá para separar pelo cabeçalho: quem tem
 * Twitch-Eventsub-Message-Type é a própria Twitch entregando um aviso; o
 * resto é o streamer ligando ou conferindo as assinaturas.
 *
 * A ordem aqui importa mais do que em qualquer outro arquivo do projeto:
 *
 *  1. O corpo é lido CRU e guardado. A assinatura é sobre esses bytes exatos —
 *     decodificar e recodificar muda o JSON e o HMAC nunca mais bate.
 *  2. O desafio de verificação sai CRU, sem aspas e sem quebra de linha. Um
 *     único aviso do PHP impresso antes disso derruba a assinatura inteira.
 *  3. Responde rápido. A Twitch cancela quem demora, e o trabalho pesado vai
 *     depois de responder.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/twitch.php';
require_once __DIR__ . '/lib/seguranca.php';

/* ------------------------------------------------------------------ *
 *  Vindo da Twitch
 * ------------------------------------------------------------------ */

$tipoMsg = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_TYPE'] ?? '';

if ($tipoMsg !== '') {
    // Os bytes exatos, uma vez só. Tudo depois disso usa esta variável.
    $cru = file_get_contents('php://input');

    $id    = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_ID'] ?? '';
    $hora  = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_TIMESTAMP'] ?? '';
    $ass   = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_SIGNATURE'] ?? '';
    $dados = json_decode($cru, true) ?: [];

    // Aviso velho é tentativa de repetir uma mensagem antiga.
    if ($id === '' || $hora === '' || abs(time() - strtotime($hora)) > 600) {
        http_response_code(403);
        exit;
    }

    // De qual canal é este aviso? Cada um tem o seu segredo.
    $twitch_id = (string) ($dados['subscription']['condition']['broadcaster_user_id'] ?? '');
    $st = db()->prepare('SELECT id, es_segredo FROM usuarios WHERE twitch_user_id = ? LIMIT 1');
    $st->execute([$twitch_id]);
    $u = $st->fetch();

    if (!$u || !$u['es_segredo']) {
        http_response_code(403);
        exit;
    }

    $calc = 'sha256=' . hash_hmac('sha256', $id . $hora . $cru, $u['es_segredo']);
    if (!hash_equals($calc, $ass)) {
        http_response_code(403);
        exit;
    }

    // ---------- o aperto de mão inicial ----------
    if ($tipoMsg === 'webhook_callback_verification') {
        $desafio = (string) ($dados['challenge'] ?? '');
        header('Content-Type: text/plain');
        header('Content-Length: ' . strlen($desafio));
        echo $desafio;
        exit;
    }

    // ---------- a Twitch desistiu da assinatura ----------
    if ($tipoMsg === 'revocation') {
        db()->prepare('UPDATE eventsub_assinaturas SET estado = ? WHERE twitch_id = ?')
            ->execute([
                (string) ($dados['subscription']['status'] ?? 'revoked'),
                (string) ($dados['subscription']['id'] ?? ''),
            ]);
        http_response_code(204);
        exit;
    }

    // ---------- aviso de verdade ----------
    // A entrega é "pelo menos uma vez": guardar o id ANTES de agir é o que
    // impede um seguidor de virar dois.
    try {
        db()->prepare('INSERT INTO eventsub_recebidos (mensagem_id) VALUES (?)')->execute([$id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { http_response_code(204); exit; }
        throw $e;
    }

    http_response_code(204);
    responder_e_continuar();

    // Daqui pra baixo a Twitch já foi embora.
    try {
        tratar_evento((int) $u['id'], (string) ($dados['subscription']['type'] ?? ''), (array) ($dados['event'] ?? []));
    } catch (Throwable $e) { /* um aviso perdido não pode derrubar os próximos */ }

    // Faxina barata: uma vez a cada tantos avisos, sem cron.
    if (random_int(1, 200) === 1) {
        db()->exec('DELETE FROM eventsub_recebidos WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    }
    exit;
}

/**
 * O que fazer com cada aviso.
 *
 * Sub e bits já chegam pelo chat e são contados lá — se contássemos aqui
 * também, cada sub valeria em dobro. Por isso este lado só cuida do que o
 * chat não vê.
 */
function tratar_evento(int $usuario_id, string $tipo, array $ev): void
{
    if ($tipo !== 'channel.follow') {
        return;
    }

    require_once __DIR__ . '/lib/subathon-somar.php';
    subathon_somar($usuario_id, [
        'tipo'    => 'follow',
        'chave'   => 'follow:' . ($ev['user_id'] ?? ''),
        'quem'    => (string) ($ev['user_name'] ?? 'alguém'),
        'detalhe' => 'começou a seguir',
    ]);
}

/* ------------------------------------------------------------------ *
 *  Vindo do streamer: ligar e conferir
 * ------------------------------------------------------------------ */

require_once __DIR__ . '/lib/acesso.php';
cors();
$quem = exige_painel();

const TIPOS = [
    // channel.follow é versão 2 e pede um moderador na condição — o próprio
    // dono do canal serve, desde que ele tenha dado moderator:read:followers.
    'channel.follow' => ['versao' => '2', 'moderador' => true],
];

$bid = tw_broadcaster_id($quem['usuario_id']);

// ---------- conferir ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = db()->prepare('SELECT tipo, estado, criado_em FROM eventsub_assinaturas WHERE usuario_id = ?');
    $st->execute([$quem['usuario_id']]);
    $tem = $st->fetchAll();

    $st = db()->prepare('SELECT tw_escopos FROM usuarios WHERE id = ?');
    $st->execute([$quem['usuario_id']]);
    $escopos = explode(' ', (string) $st->fetchColumn());

    json_saida([
        'assinaturas'    => $tem,
        'falta_escopo'   => !in_array('moderator:read:followers', $escopos, true),
        'tipos'          => array_keys(TIPOS),
    ]);
}

// ---------- ligar ----------
trava('eventsub', 10, 300);

$pdo = db();
$st = $pdo->prepare('SELECT es_segredo FROM usuarios WHERE id = ?');
$st->execute([$quem['usuario_id']]);
$segredo = (string) $st->fetchColumn();

if ($segredo === '') {
    $segredo = bin2hex(random_bytes(24));
    $pdo->prepare('UPDATE usuarios SET es_segredo = ? WHERE id = ?')
        ->execute([$segredo, $quem['usuario_id']]);
}

$callback = rtrim(cfg()['api_base'] ?? 'https://api.zocahop.com', '/') . '/eventsub.php';
$feitas = [];
$erros  = [];

foreach (TIPOS as $tipo => $spec) {
    // Já existe? A Twitch aceita no máximo 3 iguais, e um cron que recria às
    // cegas passaria a contar cada evento três vezes.
    $st = $pdo->prepare('SELECT twitch_id FROM eventsub_assinaturas WHERE usuario_id = ? AND tipo = ?');
    $st->execute([$quem['usuario_id'], $tipo]);
    if ($st->fetchColumn()) {
        $feitas[] = $tipo . ' (já estava)';
        continue;
    }

    $condicao = ['broadcaster_user_id' => $bid];
    if (!empty($spec['moderador'])) {
        $condicao['moderator_user_id'] = $bid;
    }

    [$http, $r] = tw_helix_app('POST', '/eventsub/subscriptions', [], [
        'type'      => $tipo,
        'version'   => $spec['versao'],
        'condition' => $condicao,
        'transport' => ['method' => 'webhook', 'callback' => $callback, 'secret' => $segredo],
    ]);

    if ($http !== 202 && $http !== 200) {
        $erros[] = $tipo . ': ' . ($r['message'] ?? "http $http");
        continue;
    }

    $pdo->prepare(
        'INSERT INTO eventsub_assinaturas (usuario_id, tipo, twitch_id, estado) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE twitch_id = VALUES(twitch_id), estado = VALUES(estado)'
    )->execute([
        $quem['usuario_id'], $tipo,
        (string) ($r['data'][0]['id'] ?? ''), (string) ($r['data'][0]['status'] ?? 'pending'),
    ]);
    $feitas[] = $tipo;
}

json_saida(['ok' => empty($erros), 'ligadas' => $feitas, 'erros' => $erros]);
