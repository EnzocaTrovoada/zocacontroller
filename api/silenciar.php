<?php
/**
 * As chaves de silêncio do painel: mensagens de voz e alertas.
 *
 * GET               → como estão as duas
 * POST {voz: 0|1}   → liga ou desliga o TTS
 * POST {alertas:..} → liga ou desliga TODOS os alertas
 *
 * NENHUMA DELAS É DO PRO, E É DE PROPÓSITO.
 *
 * Quem transmite é quem leva o banimento pelo que sai no som e na tela
 * dele. Cobrar por um botão de desligar seria cobrar pelo freio.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/tts.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/** A chave dos alertas, com o padrão de quem nunca mexeu. */
function silencio_alertas(int $uid): bool
{
    try {
        $st = db()->prepare('SELECT alertas_ligados FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $v = $st->fetchColumn();
        return $v === false ? true : (bool) $v;
    } catch (Throwable $e) {
        return true;                 /* sem o SQL 065: seguem ligados */
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $tts = tts_config($uid);
    json_saida([
        'voz'      => (int) $tts['ligado'],
        /* A tela precisa saber se o TTS sequer está montado: uma chave de
           desligar algo que a pessoa nunca ligou é uma chave sem sentido. */
        'voz_tem'  => (string) $tts['premio_id'] !== '',
        'alertas'  => silencio_alertas($uid) ? 1 : 0,
    ]);
}

$d = corpo_json();

if (isset($d['voz'])) {
    try {
        db()->prepare('INSERT INTO tts_config (usuario_id, ligado) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE ligado = VALUES(ligado)')
            ->execute([$uid, empty($d['voz']) ? 0 : 1]);

        /* Desligar também esvazia a fila: senão o que já estava esperando
           sairia assim mesmo, e "desliguei e continuou falando" é o mesmo
           que não ter desligado. */
        if (empty($d['voz'])) {
            db()->prepare('DELETE FROM tts_fila WHERE usuario_id = ? AND falado_em IS NULL')
                ->execute([$uid]);
            db()->prepare('UPDATE tts_config SET calar_em = NOW() WHERE usuario_id = ?')
                ->execute([$uid]);
        }
    } catch (Throwable $e) {
        json_saida(['erro' => 'Falta rodar o SQL 062 no banco.'], 500);
    }
}

if (isset($d['alertas'])) {
    try {
        db()->prepare('UPDATE usuarios SET alertas_ligados = ? WHERE id = ?')
            ->execute([empty($d['alertas']) ? 0 : 1, $uid]);
    } catch (PDOException $e) {
        json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 065 no banco.')], 500);
    }
}

$tts = tts_config($uid);
json_saida([
    'ok'      => true,
    'voz'     => (int) $tts['ligado'],
    'alertas' => silencio_alertas($uid) ? 1 : 0,
]);
