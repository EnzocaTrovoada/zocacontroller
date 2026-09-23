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

    /* De qual canal é este aviso? Cada um tem o seu segredo.

       O channel.raid não traz broadcaster_user_id: a condição dele é
       from_broadcaster_user_id, porque ele escuta pelo lado de quem manda
       o raid. Sem olhar os dois, o aviso de raid cairia aqui como "canal
       desconhecido" e sumiria calado — o recurso não funcionaria e não
       haveria erro nenhum pra seguir. */
    $cond = (array) ($dados['subscription']['condition'] ?? []);
    $twitch_id = (string) ($cond['broadcaster_user_id'] ?? $cond['from_broadcaster_user_id'] ?? '');
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
    /* O RESGATE DE PONTOS, QUE VIRA FALA.

       O texto vem de um espectador, então ele NÃO é usado pra mais nada
       além de virar fala: não vira endereço, não vira comando, não vira
       HTML. O tts_enfileira() limpa antes de guardar, e quem lê escreve
       com textContent. A regra não tem exceção. */
    if ($tipo === 'channel.channel_points_custom_reward_redemption.add') {
        require_once __DIR__ . '/lib/tts.php';

        $cfg = tts_config($usuario_id);
        $premio = (string) ($ev['reward']['id'] ?? '');

        /* Prêmio amarrado: só ele fala. Sem amarrar, nenhum fala — senão
           qualquer resgate do canal viraria voz, inclusive os que a pessoa
           criou pra outra coisa. */
        if ($cfg['premio_id'] === '' || $cfg['premio_id'] !== $premio) {
            return;
        }

        $texto = (string) ($ev['user_input'] ?? '');
        $quem  = (string) ($ev['user_name'] ?? '');

        /* O PREFIXO ESCOLHE A VOZ, E SÓ CONTRA A LISTA FECHADA.

           "grave: oi" fala em grave. O que não casa com uma voz do catálogo
           NÃO é engolido: a mensagem inteira é lida na voz padrão. Engolir
           viraria jeito de sumir com o que o outro escreveu. */
        $voz = '';
        if ((int) $cfg['prefixo'] && preg_match('/^\s*([a-z]{3,12})\s*:\s*(.+)$/isu', $texto, $m)) {
            $tentou = mb_strtolower($m[1]);
            if (in_array($tentou, tts_vozes_do_canal($cfg), true)) {
                $voz = $tentou;
                $texto = $m[2];
            }
        }

        tts_enfileira($usuario_id, $texto, $quem, $voz);
        return;
    }

    /* QUANDO A LIVE COMEÇOU E QUANDO ACABOU.

       Guardado na hora, porque na hora do raid já é tarde: muita gente
       encerra a live logo depois de raidar, e aí a Twitch responde "não
       está ao vivo" e a duração se perde. */
    if ($tipo === 'stream.online') {
        try {
            db()->prepare('UPDATE usuarios SET ao_vivo_desde = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s', strtotime((string) ($ev['started_at'] ?? 'now'))), $usuario_id]);
        } catch (Throwable $e) { /* sem o SQL 063: os raids não contam */ }
        return;
    }

    if ($tipo === 'stream.offline') {
        try {
            db()->prepare('UPDATE usuarios SET ao_vivo_desde = NULL WHERE id = ?')->execute([$usuario_id]);
        } catch (Throwable $e) { /* idem */ }
        return;
    }

    /* O RAID QUE ACONTECEU DE VERDADE.

       Este é o aviso que a própria Twitch manda quando o raid ocorreu, com
       quantos espectadores foram. É a única prova que o cliente não tem
       como forjar — e por isso o ponto nasce AQUI, e nunca no clique do
       botão do painel. De quebra, raid dado direto pela Twitch, sem passar
       pelo nosso painel, conta igual. */
    if ($tipo === 'channel.raid') {
        require_once __DIR__ . '/lib/raid-pontos.php';
        raid_registrar(
            $usuario_id,
            (string) ($ev['to_broadcaster_user_id'] ?? ''),
            (string) ($ev['to_broadcaster_user_login'] ?? ''),
            (int) ($ev['viewers'] ?? 0)
        );
        return;
    }

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
    // O resgate de pontos: é ele que faz o TTS existir. Sem moderador na
    // condição, e o escopo é channel:read:redemptions.
    'channel.channel_points_custom_reward_redemption.add' => ['versao' => '1'],
    // Quando a live começa. É o que permite saber, na hora do raid, se ela
    // já durou as duas horas que os pontos exigem. Não pede escopo.
    'stream.online'  => ['versao' => '1'],
    'stream.offline' => ['versao' => '1'],
    // O raid que de fato aconteceu, com quantos espectadores foram. Não
    // pede escopo, e é a única prova que o cliente não tem como forjar.
    'channel.raid'   => ['versao' => '1', 'de' => true],
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

$callback = api_base() . '/eventsub.php';
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

    /* O channel.raid é o único que escuta pelo lado de QUEM MANDA: a
       condição dele é from_broadcaster_user_id, e não broadcaster_user_id.
       Assinar com a chave errada daria um evento que nunca chega. */
    $condicao = !empty($spec['de'])
        ? ['from_broadcaster_user_id' => $bid]
        : ['broadcaster_user_id' => $bid];
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
