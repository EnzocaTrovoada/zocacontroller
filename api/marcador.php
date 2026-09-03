<?php
/**
 * Marcadores de momento.
 *
 * O valor está no tempo_live: o obs-websocket sabe há quanto tempo a
 * transmissão está no ar, e é esse número que bate com o VOD. Hora do relógio
 * não ajuda ninguém a achar o trecho depois.
 *
 * POST — a ponte grava (ela é quem sabe o tempo da live)
 * GET  — painel e mods leem a lista
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = quem_chama();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($quem['tipo'] !== 'painel') {
        json_saida(['erro' => 'Só a ponte grava marcador.'], 403);
    }
    trava('marcador', 60, 60);

    $d    = corpo_json();
    $nota = trim((string) ($d['nota'] ?? ''));
    $por  = trim((string) ($d['quem'] ?? 'alguém'));

    if (mb_strlen($nota) > 200) {
        $nota = mb_substr($nota, 0, 200);
    }

    // "01:23:45.678" vira "01:23:45": milésimo não ajuda a achar nada no VOD.
    $tempo = trim((string) ($d['tempo_live'] ?? ''));
    $tempo = preg_match('/^\d{1,3}:\d{2}:\d{2}/', $tempo) ? substr($tempo, 0, 8) : null;

    db()->prepare(
        'INSERT INTO marcadores (usuario_id, quem, nota, tempo_live, ao_vivo)
              VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $quem['usuario_id'],
        mb_substr($por, 0, 64),
        $nota !== '' ? $nota : null,
        $tempo,
        !empty($d['ao_vivo']) ? 1 : 0,
    ]);

    json_saida(['ok' => true, 'tempo_live' => $tempo]);
}

// ---------- leitura ----------
// Por padrão só as últimas horas: marcador serve para a live de hoje, e a
// lista inteira só cresceria sem ninguém olhar.
$horas = min(72, max(1, (int) ($_GET['horas'] ?? 12)));

$st = db()->prepare(
    'SELECT quem, nota, tempo_live, ao_vivo,
            TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS ha_segundos
       FROM marcadores
      WHERE usuario_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL ? HOUR)
      ORDER BY id DESC
      LIMIT 200'
);
$st->execute([$quem['usuario_id'], $horas]);

json_saida(['marcadores' => array_map(fn($m) => [
    'quem'       => $m['quem'],
    'nota'       => $m['nota'],
    'tempo_live' => $m['tempo_live'],
    'ao_vivo'    => (bool) $m['ao_vivo'],
    // Segundos atras, nao data: DATETIME sem fuso vira hora errada no navegador.
    'ha_segundos' => (int) $m['ha_segundos'],
], $st->fetchAll())]);
