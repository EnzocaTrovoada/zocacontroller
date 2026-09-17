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

$uid = (int) $quem['usuario_id'];
$d   = corpo_json();

/* ---------- o teste do painel ----------

   Manda uma mensagem de verdade no chat, sem passar pela ponte. É o que
   separa "o servidor não consegue falar com a Twitch" de "a fonte do OBS
   não está chamando o servidor". O texto é fixo de propósito: este
   endereço não é um megafone. */
if (!empty($d['teste'])) {
    trava('responder-teste', 6, 600);
    $escopos = chat_escopos($uid);
    try {
        $r = chat_enviar($uid, 'Teste do ZocaController: o bot está falando aqui.');
    } catch (Throwable $e) {
        $r = ['ok' => false, 'erro' => erro_publico($e)];
    }
    json_saida($r + [
        'bot'          => chat_bot_id() !== '',
        'escopo_conta' => in_array('user:write:chat', $escopos, true),
        'escopo_bot'   => in_array('channel:bot', $escopos, true),
    ]);
}

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
$ehRecado = false;
$cedo = false;
$sumiu = false;

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
    } elseif (!empty($d['recado'])) {
        /* O RELÓGIO É DAQUI. A ponte só pergunta se está na hora; quem
           responde é o ultimo_em, num UPDATE que só passa uma vez. Duas
           pontes abertas não viram dois recados. */
        $st = db()->prepare('SELECT * FROM recados WHERE id = ? AND usuario_id = ?');
        $st->execute([(int) $d['recado'], $uid]);
        $r = $st->fetch();
        $msgs = $r ? json_decode((string) $r['mensagens'], true) : null;
        $sumiu = !$r || !is_array($msgs) || !$msgs;

        if (!$sumiu) {
            $aoVivo = !empty($d['ao_vivo']);
            $vale = $aoVivo ? (int) $r['no_ar'] : (int) $r['fora_do_ar'];
            $cedo = !(int) $r['ligado'] || !$vale || (int) ($d['linhas'] ?? 0) < (int) $r['linhas'];

            if (!$cedo) {
                $marca = db()->prepare(
                    'UPDATE recados SET ultimo_em = NOW(), proxima = proxima + 1
                      WHERE id = ? AND usuario_id = ?
                        AND (ultimo_em IS NULL OR ultimo_em < DATE_SUB(NOW(), INTERVAL minutos MINUTE))'
                );
                $marca->execute([(int) $r['id'], $uid]);
                $cedo = !$marca->rowCount();
            }

            if (!$cedo) {
                $texto = (string) $msgs[((int) $r['proxima']) % count($msgs)];
                $contador = 'recado-' . (int) $r['id'];
                $ehRecado = true;
            }
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

/* O recado ainda não deu a hora, ou o chat está parado: não é erro. */
if ($cedo)  json_saida(['ok' => true, 'cedo' => true]);
if ($sumiu) json_saida(['erro' => 'Esse recado não existe mais.'], 404);

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

/* Num recado não existe "quem mandou": quem fala é o canal. */
if ($ehRecado) {
    $ctx['quem']  = chat_canal_campo($uid, 'display_name', '');
    $ctx['login'] = chat_canal_campo($uid, '', '');
    $ctx['cargo'] = 'dono';
}

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
