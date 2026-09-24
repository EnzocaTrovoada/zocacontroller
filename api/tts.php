<?php
/**
 * O TTS: a config do canal e o disparo manual.
 *
 * GET                 → como está, e o catálogo de vozes
 * POST {ligado, ...}  → muda
 * POST {testar, voz}  → põe uma frase de teste na fila
 *
 * Quem enche a fila de verdade é o resgate de pontos do canal, que chega
 * pelo EventSub — não este endereço. Aqui é o painel.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/tts.php';
require_once __DIR__ . '/lib/twitch.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/* ---------- os prêmios de pontos que o canal já tem ----------

   PEDIR UM UUID SERIA PEDIR DEMAIS. O público disto não sabe o que é id de
   prêmio, e mandar copiar da Twitch é onde a pessoa desiste. Aqui a lista
   vem pronta, e quem não tem prêmio nenhum ganha um com um clique. */
if (isset($_GET['premios'])) {
    $bid = tw_broadcaster_id($uid);
    if (!$bid) json_saida(['premios' => [], 'erro' => 'Entre de novo com a Twitch.']);

    [$http, $r] = tw_helix($uid, 'GET', '/channel_points/custom_rewards',
                           ['broadcaster_id' => $bid]);

    /* 403 aqui quase sempre é conta sem afiliado: pontos de canal só
       existem pra afiliado e parceiro. Dizer isso é mais útil que "erro". */
    if ($http === 403) {
        json_saida(['premios' => [], 'erro' => 'Pontos do canal são de afiliado pra cima.']);
    }
    if ($http !== 200) {
        json_saida(['premios' => [], 'erro' => 'Não deu pra ler seus prêmios agora.']);
    }

    $lista = [];
    foreach ((array) ($r['data'] ?? []) as $p) {
        $lista[] = ['id' => (string) $p['id'], 'nome' => (string) $p['title'],
                    'custo' => (int) $p['cost']];
    }
    json_saida(['premios' => $lista]);
}

/* ---------- por que o TTS não fala ----------

   SÃO SEIS ELOS, E QUEBRAR UM DELES NÃO DÁ ERRO NENHUM.

   O resgate precisa: estar ligado aqui, ter um prêmio amarrado, ter a
   permissão da Twitch, ter a assinatura do EventSub criada, ter a overlay
   no OBS e a fonte não estar muda. Faltando qualquer um, o espectador
   gasta os pontos e nada acontece — em silêncio.

   Sem esta conferência, achar o elo quebrado é tentativa e erro. */
if (isset($_GET['checar'])) {
    $c = tts_config($uid);
    $escopos = tw_escopos($uid);
    $passos = [];

    $passos[] = ['o_que' => 'O TTS está ligado',
        'ok' => (bool) (int) $c['ligado'],
        'como' => 'Marque "Ligado" aqui em cima e salve.'];

    $passos[] = ['o_que' => 'Tem um prêmio de pontos escolhido',
        'ok' => (string) $c['premio_id'] !== '',
        'como' => 'Escolha um na lista, ou clique em "Criar o prêmio pra mim".'];

    $passos[] = ['o_que' => 'A Twitch deixa a gente ver os resgates',
        'ok' => in_array('channel:read:redemptions', $escopos, true),
        'como' => 'Meu ZocaHub → Entrar de novo na Twitch.'];

    /* A assinatura é o elo mais silencioso: ela só nasce quando a pessoa
       liga o EventSub, e quem ligou ANTES do TTS existir não tem esta. */
    /* PROCURA POR PREFIXO, E NÃO PELO NOME INTEIRO.

       A coluna era VARCHAR(48) e este nome tem 51 caracteres: quem
       assinou antes do SQL 067 tem a linha com o nome cortado. Procurar
       pelo nome completo não acha ela, e a tela pede pra ligar uma coisa
       que já está ligada — e ligar de novo não muda nada, porque o nome
       cortado colide com o UNIQUE.

       O prefixo acha os dois casos, o cortado e o inteiro. */
    $temAssinatura = false;
    try {
        $st = db()->prepare(
            "SELECT estado FROM eventsub_assinaturas
              WHERE usuario_id = ? AND tipo LIKE 'channel.channel_points_custom_reward_redem%'"
        );
        $st->execute([$uid]);
        $e = $st->fetchColumn();
        $temAssinatura = $e !== false && $e !== 'revoked' && $e !== 'authorization_revoked';
    } catch (Throwable $e) { /* sem tabela: conta como faltando */ }

    $passos[] = ['o_que' => 'A Twitch está avisando a gente dos resgates',
        'ok' => $temAssinatura,
        'como' => 'Meu ZocaHub → Ligar os avisos da Twitch (EventSub).'];

    $temOverlay = false;
    try {
        $st = db()->prepare("SELECT 1 FROM perfis WHERE usuario_id = ? AND tipo = 'tts' LIMIT 1");
        $st->execute([$uid]);
        $temOverlay = (bool) $st->fetchColumn();
    } catch (Throwable $e) { /* idem */ }

    $passos[] = ['o_que' => 'A overlay de TTS existe',
        'ok' => $temOverlay,
        'como' => 'Overlays → TTS. É ela que fala; sem ela não sai som.'];

    $faltam = array_values(array_filter($passos, static fn($p) => !$p['ok']));

    json_saida([
        'tudo_certo' => !$faltam,
        'passos'     => $passos,
        /* O primeiro que falta é o que resolver: os de baixo podem depender
           dele, e uma lista de cinco pendências desanima. */
        'primeiro'   => $faltam[0] ?? null,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $c = tts_config($uid);
    json_saida([
        'ligado'     => (int) $c['ligado'],
        'voz'        => (string) $c['voz'],
        'vozes'      => preg_split('/[\s,]+/', (string) $c['vozes'], -1, PREG_SPLIT_NO_EMPTY),
        'aleatorio'  => (int) $c['aleatorio'],
        'prefixo'    => (int) $c['prefixo'],
        'max_letras' => (int) $c['max_letras'],
        'bloqueadas' => (string) ($c['bloqueadas'] ?? ''),
        'premio_id'  => (string) $c['premio_id'],
        'catalogo'   => TTS_VOZES,
        /* O que este plano libera. A tela desliga o que não dá, em vez de
           deixar clicar e levar erro. */
        'varias'     => (bool) limite($uid, 'tts_varias_vozes', 1),
        'pode'       => (bool) limite($uid, 'tts', 1),
    ]);
}

$d = corpo_json();

/* ---------- criar o prêmio pela pessoa ---------- */
if (!empty($d['criar_premio'])) {
    trava('tts-premio', 5, 600);
    $bid = tw_broadcaster_id($uid);
    if (!$bid) json_saida(['erro' => 'Entre de novo com a Twitch.'], 400);

    [$http, $r] = tw_helix($uid, 'POST', '/channel_points/custom_rewards',
        ['broadcaster_id' => $bid], [
            'title'   => 'Fazer o bot falar',
            'cost'    => max(1, min(1000000, (int) ($d['custo'] ?? 500))),
            'prompt'  => 'Escreva o que o bot vai falar na live.',
            /* SEM TEXTO NÃO HÁ FALA. Prêmio que não pede mensagem resgata
               vazio, e aí o TTS não teria o que dizer. */
            'is_user_input_required'             => true,
            'should_redemptions_skip_request_queue' => true,
        ]);

    if ($http === 403) json_saida(['erro' => 'Pontos do canal são de afiliado pra cima.'], 400);
    if ($http !== 200) {
        json_saida(['erro' => (string) ($r['message'] ?? 'A Twitch recusou criar o prêmio.')], 400);
    }

    $novo = (string) ($r['data'][0]['id'] ?? '');
    try {
        db()->prepare('INSERT INTO tts_config (usuario_id, premio_id) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE premio_id = VALUES(premio_id)')
            ->execute([$uid, $novo]);
    } catch (Throwable $e) {
        json_saida(['erro' => 'Falta rodar o SQL 062 no banco.'], 500);
    }
    json_saida(['ok' => true, 'premio_id' => $novo]);
}

/* ---------- calar o bot agora ----------

   O PEDIDO MAIS IMPORTANTE DESTE ARQUIVO. Quem transmite precisa conseguir
   cortar uma frase no meio: é ele que leva o banimento pelo que sai no som
   dele. Por isso não é do Pro, não tem trava de plano e o pedido é curto.

   Apaga o que está na fila e marca o instante: a fonte vê o instante novo
   na leitura seguinte (a cada 3 segundos) e corta o que estiver falando. */
if (!empty($d['calar'])) {
    try {
        db()->prepare('DELETE FROM tts_fila WHERE usuario_id = ? AND falado_em IS NULL')
            ->execute([$uid]);
        db()->prepare('INSERT INTO tts_config (usuario_id, calar_em) VALUES (?, NOW())
                       ON DUPLICATE KEY UPDATE calar_em = NOW()')
            ->execute([$uid]);
    } catch (Throwable $e) {
        json_saida(['erro' => 'Falta rodar o SQL 062 no banco.'], 500);
    }
    json_saida(['ok' => true]);
}

/* ---------- ouvir como ficou ---------- */
if (!empty($d['testar'])) {
    trava('tts-testar', 10, 300);
    $voz = (string) ($d['voz'] ?? '');
    $motivo = tts_enfileira($uid, 'Assim que eu vou falar no seu chat.', '', $voz);
    if ($motivo === 'desligado') json_saida(['erro' => 'Ligue o TTS primeiro.'], 400);
    if ($motivo === 'sem-tabela') json_saida(['erro' => 'Falta rodar o SQL 062 no banco.'], 500);
    if ($motivo !== '') json_saida(['erro' => 'Não deu pra testar agora.'], 429);
    json_saida(['ok' => true]);
}

/* ---------- salvar ---------- */
if (!limite($uid, 'tts', 1)) {
    json_saida(['erro' => 'O TTS é do Pro.'], 402);
}

$voz = in_array($d['voz'] ?? '', TTS_VOZES, true) ? (string) $d['voz'] : 'padrao';

/* Só vozes do catálogo, e só uma pra quem não é Pro: o que a tela oferece
   não é o que manda — quem manda é isto. */
$vozes = array_values(array_intersect(
    array_map('strval', (array) ($d['vozes'] ?? [])),
    TTS_VOZES
));
if (!limite($uid, 'tts_varias_vozes', 1)) $vozes = array_slice($vozes, 0, 1);

$cfg = [
    'ligado'     => !empty($d['ligado']) ? 1 : 0,
    'voz'        => $voz,
    'vozes'      => implode(' ', array_slice($vozes, 0, 20)),
    'aleatorio'  => !empty($d['aleatorio']) && count($vozes) > 1 ? 1 : 0,
    'prefixo'    => !empty($d['prefixo']) && limite($uid, 'tts_prefixo', 1) ? 1 : 0,
    'max_letras' => max(20, min(400, (int) ($d['max_letras'] ?? 200))),
    /* Palavra barrada é texto do streamer, não expressão regular: o que
       chega aqui é escapado antes de virar busca, lá no tts_limpa(). */
    'bloqueadas' => mb_substr(trim((string) ($d['bloqueadas'] ?? '')), 0, 2000),
    'premio_id'  => preg_match('/^[0-9a-f-]{0,64}$/i', (string) ($d['premio_id'] ?? ''))
        ? (string) $d['premio_id'] : '',
];

try {
    db()->prepare(
        'INSERT INTO tts_config (usuario_id, ligado, voz, vozes, aleatorio, prefixo,
                                 max_letras, bloqueadas, premio_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE ligado = VALUES(ligado), voz = VALUES(voz),
             vozes = VALUES(vozes), aleatorio = VALUES(aleatorio), prefixo = VALUES(prefixo),
             max_letras = VALUES(max_letras), bloqueadas = VALUES(bloqueadas),
             premio_id = VALUES(premio_id)'
    )->execute([$uid, $cfg['ligado'], $cfg['voz'], $cfg['vozes'], $cfg['aleatorio'],
                $cfg['prefixo'], $cfg['max_letras'], $cfg['bloqueadas'], $cfg['premio_id']]);
} catch (PDOException $e) {
    json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 062 no banco.')], 500);
}

json_saida(['ok' => true] + $cfg);
