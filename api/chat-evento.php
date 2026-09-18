<?php
/**
 * Cada mensagem do chat, vinda da Twitch.
 *
 * É o que faz o comando responder com o OBS fechado: em vez da fonte do OBS
 * ler o chat, a Twitch avisa aqui (EventSub channel.chat.message) e o bot
 * responde. As ações no OBS continuam com a fonte — daqui não dá pra trocar
 * de cena na máquina de ninguém.
 *
 * ESTE É O ARQUIVO MAIS CHAMADO DO SISTEMA: uma vez por mensagem de chat, de
 * todo canal que ligou o bot. Tudo aqui é curto e sai cedo:
 *
 *  1. Os bytes crus, uma vez só — a assinatura é sobre eles.
 *  2. Mensagem que não começa com "!" morre na terceira linha de trabalho.
 *  3. A resposta sai antes do trabalho, com litespeed_finish_request.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/chat.php';

$cru  = file_get_contents('php://input');
$tipo = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_TYPE'] ?? '';
$id   = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_ID'] ?? '';
$hora = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_TIMESTAMP'] ?? '';
$ass  = $_SERVER['HTTP_TWITCH_EVENTSUB_MESSAGE_SIGNATURE'] ?? '';

if ($tipo === '' || $id === '' || $hora === '' || abs(time() - strtotime($hora)) > 600) {
    http_response_code(403);
    exit;
}

$dados = json_decode($cru, true) ?: [];
$subId = (string) ($dados['subscription']['id'] ?? '');
$doCanal = (string) ($dados['subscription']['condition']['broadcaster_user_id'] ?? '');

/* PELO ID DA ASSINATURA, OU PELO DONO DO CANAL.

   Na confirmação do endereço a Twitch chega antes de a gente ter gravado o
   id da assinatura: ela cria e confirma no mesmo segundo. Procurar também
   pelo canal faz a primeira confirmação passar. */
try {
    $st = db()->prepare(
        'SELECT b.* FROM bot_chat b JOIN usuarios u ON u.id = b.usuario_id
          WHERE b.sub_id = ? OR u.twitch_user_id = ? LIMIT 1'
    );
    $st->execute([$subId, $doCanal]);
    $canal = $st->fetch();
} catch (Throwable $e) {
    $canal = null;
}

if (!$canal) {
    http_response_code(403);
    exit;
}

if (!hash_equals('sha256=' . hash_hmac('sha256', $id . $hora . $cru, (string) $canal['segredo']), $ass)) {
    http_response_code(403);
    exit;
}

/* ---------- o aperto de mão inicial ---------- */
if ($tipo === 'webhook_callback_verification') {
    $desafio = (string) ($dados['challenge'] ?? '');
    header('Content-Type: text/plain');
    header('Content-Length: ' . strlen($desafio));
    echo $desafio;
    exit;
}

/* ---------- a Twitch desistiu ---------- */
if ($tipo === 'revocation') {
    try {
        db()->prepare('UPDATE bot_chat SET ligado = 0 WHERE sub_id = ?')->execute([$subId]);
    } catch (Throwable $e) { /* nada a fazer */ }
    http_response_code(204);
    exit;
}

if ($tipo !== 'notification') {
    http_response_code(204);
    exit;
}

$uid = (int) $canal['usuario_id'];
$ev  = (array) ($dados['event'] ?? []);
$texto = trim((string) ($ev['message']['text'] ?? ''));

/* O bot não conversa com ele mesmo, e o que não é comando não interessa. */
if ($texto === '' || $texto[0] !== '!'
    || (string) ($ev['chatter_user_id'] ?? '') === chat_bot_id()
    || !(int) $canal['ligado']) {
    http_response_code(204);
    exit;
}

/* Responde agora; o resto acontece com a Twitch já despachada. */
http_response_code(204);
responder_e_continuar();

/* ------------------------------------------------------------------ *
 *  Daqui pra baixo a Twitch já foi embora
 * ------------------------------------------------------------------ */

/** O cargo de quem falou, pelas etiquetas que a Twitch mandou. */
function chat_cargo(array $badges, array $supermods, string $login): string
{
    $tem = [];
    foreach ($badges as $b) $tem[strtolower((string) ($b['set_id'] ?? ''))] = true;

    if (isset($tem['broadcaster'])) return 'dono';
    $chefe = isset($tem['lead_moderator']);
    $mod   = $chefe || isset($tem['moderator']);
    if ($chefe || ($mod && in_array($login, $supermods, true))) return 'supermod';
    if ($mod) return 'mod';
    if (isset($tem['vip'])) return 'vip';
    if (isset($tem['subscriber']) || isset($tem['founder'])) return 'sub';
    return 'chat';
}

try {
    $corpoMsg = substr($texto, 1);
    $espaco   = strpos($corpoMsg, ' ');
    $nomeCmd  = mb_strtolower($espaco === false ? $corpoMsg : substr($corpoMsg, 0, $espaco));
    if ($nomeCmd === '' || mb_strlen($nomeCmd) > 30) exit;

    /* O comando pelo nome, ou por um dos outros nomes dele. */
    $st = db()->prepare(
        'SELECT id, nome, apelidos, quem, espera, espera_pessoa, passos
           FROM comandos
          WHERE usuario_id = ? AND ligado = 1
            AND (nome = ? OR (apelidos IS NOT NULL AND apelidos <> \'\'))'
    );
    $st->execute([$uid, $nomeCmd]);

    $cmd = null;
    foreach ($st->fetchAll() as $c) {
        if ($c['nome'] === $nomeCmd) { $cmd = $c; break; }
        $apelidos = preg_split('/[\s,]+/', mb_strtolower((string) $c['apelidos']), -1, PREG_SPLIT_NO_EMPTY);
        if (in_array($nomeCmd, $apelidos, true)) $cmd = $cmd ?: $c;
    }
    if (!$cmd) exit;

    $passos = json_decode((string) $cmd['passos'], true);
    if (!is_array($passos)) exit;

    /* Só as respostas saem daqui: cena, mute e luz são da fonte do OBS. */
    $respostas = [];
    foreach ($passos as $i => $p) {
        if (is_array($p) && ($p['acao'] ?? '') === 'responder') $respostas[$i] = $p;
    }
    if (!$respostas) exit;

    $login = strtolower((string) ($ev['chatter_user_login'] ?? ''));

    $sm = db()->prepare('SELECT supermods FROM usuarios WHERE id = ?');
    $sm->execute([$uid]);
    $supermods = preg_split('/[,\s]+/', mb_strtolower((string) $sm->fetchColumn()), -1, PREG_SPLIT_NO_EMPTY);

    $cargo = chat_cargo((array) ($ev['badges'] ?? []), $supermods, $login);
    $exige = (string) $cmd['quem'];
    if ((CHAT_NIVEL[$cargo] ?? 100) < (CHAT_NIVEL[$exige] ?? 500)) exit;

    /* As esperas, as mesmas que a fonte do OBS respeitava. */
    $espera = max(1, (int) $cmd['espera']);
    if (!limite_ok('cmd:' . (int) $cmd['id'], 1, $espera)) exit;
    $pessoal = (int) $cmd['espera_pessoa'];
    if ($pessoal > 0 && !limite_ok('cmdp:' . (int) $cmd['id'] . ':' . $login, 1, $pessoal)) exit;

    /* Uma resposta por mensagem: se a fonte do OBS também estiver
       respondendo, a primeira que chegar leva. */
    $msgId = (string) ($ev['message_id'] ?? '');

    /* Não virar megafone: teto de respostas por canal. */
    if (!limite_ok('nuvem:' . $uid, 30, 30)) exit;

    $ctx = [
        'uid'       => $uid,
        'comando'   => (string) $cmd['nome'],
        'contador'  => (string) $cmd['nome'],
        'quem'      => (string) ($ev['chatter_user_name'] ?? $login),
        'login'     => $login,
        'id_twitch' => (string) ($ev['chatter_user_id'] ?? ''),
        'cargo'     => $cargo,
        'mensagem'  => mb_substr($texto, 0, 500),
        'msg_id'    => $msgId,
        'quanto'    => '',
        'chatters'  => [],
    ];

    foreach ($respostas as $i => $p) {
        if ($msgId !== '' && !limite_ok('resp:' . $uid . ':' . $msgId . ':c' . (int) $cmd['id'] . ':' . $i, 1, 120)) {
            continue;
        }
        $saida = chat_expande((string) ($p['argumento'] ?? ''), $ctx);
        if ($saida === null || $saida === '') continue;

        $modo = in_array($p['modo'] ?? '', ['say', 'reply', 'mention'], true) ? $p['modo'] : 'say';
        $r = chat_enviar($uid, $saida, $modo, $msgId, $login);
        if (empty($r['ok'])) {
            error_log('[zc] bot no chat de ' . $uid . ': ' . (string) ($r['erro'] ?? '?'));
        }
    }

    db()->prepare('UPDATE bot_chat SET visto_em = NOW() WHERE usuario_id = ?')->execute([$uid]);
} catch (Throwable $e) {
    error_log('[zc] chat-evento: ' . get_class($e) . ': ' . $e->getMessage());
}
