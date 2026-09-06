<?php
/**
 * O canal do YouTube de quem transmite.
 *
 * GET  — qual canal está escolhido, e quantos inscritos ele tem
 * POST — escolhe pelo @handle (ou pelo id, pra quem já sabe)
 *
 * Não existe login do YouTube aqui, e é de propósito: a contagem de inscritos
 * é dado público. O caminho de OAuth pediria verificação do app pelo Google e
 * traria um teto de 100 usuários que não se reseta — caro demais pra ler um
 * número que qualquer um vê no site.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/youtube.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = db()->prepare('SELECT yt_canal, yt_handle FROM usuarios WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();

    $inscritos = null;
    $erro = '';
    if (!empty($u['yt_canal'])) {
        try { $inscritos = yt_inscritos($uid); }
        catch (Throwable $e) { $erro = $e->getMessage(); }
    }

    json_saida([
        'ligado'    => !empty($u['yt_canal']),
        'canal'     => $u['yt_canal'] ?? null,
        'handle'    => $u['yt_handle'] ?? null,
        'inscritos' => $inscritos,
        'erro'      => $erro,
    ]);
}

trava('youtube', 20, 300);
$d = corpo_json();

if (!empty($d['desligar'])) {
    db()->prepare('UPDATE usuarios SET yt_canal = NULL, yt_handle = NULL WHERE id = ?')->execute([$uid]);
    json_saida(['ok' => true, 'ligado' => false]);
}

try {
    $r = yt_resolve($uid, (string) ($d['handle'] ?? ''));
} catch (Throwable $e) {
    json_saida(['erro' => $e->getMessage()], 400);
}
json_saida(empty($r['ok']) ? ['erro' => $r['erro']] : $r, empty($r['ok']) ? 400 : 200);
