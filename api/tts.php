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

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

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
