<?php
/**
 * A ponte achou um comando que responde no chat: o texto sai daqui.
 *
 * A ponte manda QUAL comando e QUAL passo, nunca o texto: o texto é lido do
 * banco. Assim este endereço não vira um "fale qualquer coisa no chat" pra
 * quem tiver a chave, e o $(customapi) só busca endereço que o dono escreveu.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/chat.php';

cors();

$quem = quem_chama();
if ($quem['tipo'] !== 'painel') {
    json_saida(['erro' => 'Só a ponte do dono responde no chat.'], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_saida(['erro' => 'Só POST.'], 405);
}

/* A Twitch deixa o dono mandar 100 mensagens a cada 30 segundos. Aqui fica
   bem abaixo disso: resposta de comando não é conversa. */
trava('responder', 40, 30);

$uid   = (int) $quem['usuario_id'];
$d     = corpo_json();
$passo = max(0, (int) ($d['passo'] ?? 0));
$msgId = preg_match('/^[0-9a-f-]{36}$/i', (string) ($d['msg_id'] ?? '')) ? (string) $d['msg_id'] : '';

/* A MESMA MENSAGEM SÓ É RESPONDIDA UMA VEZ.

   A ponte colocada em duas cenas do OBS são duas pontes lendo o mesmo chat,
   e cada uma responderia. A primeira que chegar leva. */
$origem = !empty($d['comando']) ? 'c' . (int) $d['comando'] : 'g' . (int) ($d['gatilho'] ?? 0);
if ($msgId !== '' && !limite_ok('resp:' . $uid . ':' . $msgId . ':' . $origem . ':' . $passo, 1, 120)) {
    json_saida(['ok' => true, 'repetido' => true]);
}

$texto = null;
$modo = 'say';
$nome = '';
$contador = '';
$quanto = '';

try {
    if (!empty($d['comando'])) {
        $st = db()->prepare('SELECT nome, passos FROM comandos WHERE id = ? AND usuario_id = ?');
        $st->execute([(int) $d['comando'], $uid]);
        $c = $st->fetch();
        $passos = $c ? json_decode((string) $c['passos'], true) : null;
        $p = is_array($passos) ? ($passos[$passo] ?? null) : null;
        if (is_array($p) && ($p['acao'] ?? '') === 'responder') {
            $texto = (string) ($p['argumento'] ?? '');
            $modo = (string) ($p['modo'] ?? 'say');
            $nome = $contador = (string) $c['nome'];
        }
    } elseif (!empty($d['gatilho'])) {
        $st = db()->prepare('SELECT passos FROM gatilhos WHERE id = ? AND usuario_id = ?');
        $st->execute([(int) $d['gatilho'], $uid]);
        $passos = json_decode((string) $st->fetchColumn(), true);
        $p = is_array($passos) ? ($passos[$passo] ?? null) : null;
        if (is_array($p) && ($p['acao'] ?? '') === 'responder') {
            /* {quem} vira variável, e não o nome direto: nome de quem doa é
               texto livre, e colado no modelo ele seria lido como variável. */
            $texto = str_replace(['{quem}', '{quanto}'], ['$(sender)', '$(quanto)'], (string) ($p['argumento'] ?? ''));
            $contador = 'gatilho-' . (int) $d['gatilho'];
            $quanto = (string) max(0, (int) ($d['quanto'] ?? 0));
        }
    }
} catch (Throwable $e) {
    json_saida(['erro' => erro_publico($e)], 500);
}

if ($texto === null) {
    json_saida(['erro' => 'Esse comando não tem resposta pra mandar.'], 404);
}

$chatters = array_values(array_filter((array) ($d['chatters'] ?? []), function ($x) {
    return is_string($x) && preg_match('/^\w{1,25}$/', $x);
}));

$ctx = [
    'uid'       => $uid,
    'comando'   => $nome,
    'contador'  => $contador,
    'quem'      => mb_substr(trim((string) ($d['quem'] ?? '')), 0, 40) ?: 'alguém',
    'login'     => strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d['login'] ?? ''))),
    'id_twitch' => (string) preg_replace('/\D/', '', (string) ($d['id_twitch'] ?? '')),
    'cargo'     => in_array($d['cargo'] ?? '', ['chat', 'sub', 'vip', 'mod', 'supermod', 'dono'], true) ? $d['cargo'] : 'chat',
    'mensagem'  => mb_substr((string) ($d['mensagem'] ?? ''), 0, 500),
    'msg_id'    => $msgId,
    'quanto'    => $quanto,
    'chatters'  => array_slice($chatters, 0, 100),
];

$modo = in_array($modo, ['say', 'reply', 'mention'], true) ? $modo : 'say';
try {
    $saida = chat_expande($texto, $ctx);
    $r = ($saida === null || $saida === '') ? null : chat_enviar($uid, $saida, $modo, $msgId, $ctx['login']);
} catch (Throwable $e) {
    json_saida(['erro' => erro_publico($e)], 500);
}

/* $(if) falso sem "senão": a resposta não sai, e isso não é erro. */
if ($r === null) json_saida(['ok' => true, 'calado' => true]);
json_saida($r, $r['ok'] ? 200 : 502);
