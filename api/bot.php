<?php
/**
 * O bot dentro do chat, sem depender do OBS.
 *
 * Ligar aqui faz a Twitch avisar o nosso servidor a cada mensagem do chat
 * (EventSub channel.chat.message), e aí os comandos de resposta funcionam
 * com o OBS fechado. As ações no OBS — cena, mute, luz — continuam sendo da
 * fonte do OBS, que é quem fala com ele.
 *
 * Quem assina é o token do aplicativo, e a assinatura só custa zero porque
 * as duas pontas autorizaram: a conta do bot (user:bot e user:read:chat, em
 * entrar.php?bot=1) e quem transmite (channel:bot, na entrada normal).
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/chat.php';

cors();

$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/** A linha deste canal, ou null. */
function bot_linha(int $uid): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM bot_chat WHERE usuario_id = ?');
        $st->execute([$uid]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;   /* sem o SQL 055 ninguém ligou nada */
    }
}

/** O que falta pra poder ligar: '' quando está tudo pronto. */
function bot_falta(int $uid): string
{
    if (chat_bot_id() === '') return 'sem-bot';
    return in_array('channel:bot', chat_escopos($uid), true) ? '' : 'sem-permissao';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $linha = bot_linha($uid);
    json_saida([
        'ligado' => $linha ? (bool) $linha['ligado'] : false,
        'falta'  => bot_falta($uid),
    ]);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');
trava('bot-chat', 10, 300);

/* ---------- sair do chat ---------- */
if ($acao === 'sair') {
    $linha = bot_linha($uid);
    if ($linha && $linha['sub_id'] !== '') {
        tw_helix_app('DELETE', '/eventsub/subscriptions', ['id' => $linha['sub_id']]);
    }
    try {
        db()->prepare('DELETE FROM bot_chat WHERE usuario_id = ?')->execute([$uid]);
    } catch (Throwable $e) { /* sem a tabela, não havia nada ligado */ }
    json_saida(['ok' => true, 'ligado' => false]);
}

if ($acao !== 'entrar') {
    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* ---------- entrar no chat ---------- */
$falta = bot_falta($uid);
if ($falta === 'sem-bot') {
    json_saida(['erro' => 'O bot ainda não foi configurado neste servidor.'], 400);
}
if ($falta === 'sem-permissao') {
    json_saida(['erro' => 'Falta a permissão pra deixar o bot falar no seu canal. Entre com a Twitch de novo.'], 400);
}

$bid     = tw_broadcaster_id($uid);
$bot     = chat_bot_id();
$segredo = bin2hex(random_bytes(24));
$corpo   = [
    'type'      => 'channel.chat.message',
    'version'   => '1',
    'condition' => ['broadcaster_user_id' => $bid, 'user_id' => $bot],
    'transport' => ['method' => 'webhook', 'callback' => api_base() . '/chat-evento.php', 'secret' => $segredo],
];

[$http, $r] = tw_helix_app('POST', '/eventsub/subscriptions', [], $corpo);

/* JÁ EXISTE UMA IGUAL: ela tem um segredo que não temos mais, e sem o
   segredo não dá pra conferir a assinatura de nada que chegar. Apaga e
   refaz. */
if ($http === 409) {
    [$hl, $lista] = tw_helix_app('GET', '/eventsub/subscriptions', ['type' => 'channel.chat.message', 'user_id' => $bot]);
    foreach (($lista['data'] ?? []) as $sub) {
        if ((string) ($sub['condition']['broadcaster_user_id'] ?? '') === $bid) {
            tw_helix_app('DELETE', '/eventsub/subscriptions', ['id' => (string) $sub['id']]);
        }
    }
    [$http, $r] = tw_helix_app('POST', '/eventsub/subscriptions', [], $corpo);
}

if ($http !== 202 && $http !== 200) {
    $msg = (string) ($r['message'] ?? ('http ' . $http));
    error_log('[zc] bot no chat recusado (' . $http . '): ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    json_saida(['erro' => 'A Twitch recusou: ' . mb_substr($msg, 0, 120)], 502);
}

try {
    db()->prepare(
        'INSERT INTO bot_chat (usuario_id, sub_id, segredo, ligado) VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE sub_id = VALUES(sub_id), segredo = VALUES(segredo), ligado = 1'
    )->execute([$uid, (string) ($r['data'][0]['id'] ?? ''), $segredo]);
} catch (PDOException $e) {
    json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 055 no banco.')], 500);
}

json_saida(['ok' => true, 'ligado' => true, 'estado' => (string) ($r['data'][0]['status'] ?? 'pending')]);
