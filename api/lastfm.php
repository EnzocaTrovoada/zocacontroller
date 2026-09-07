<?php
/**
 * O nome de usuário do Last.fm de quem transmite.
 *
 * GET  — qual está guardado, e o que ele está ouvindo agora
 * POST — guarda um nome novo (ou apaga)
 *
 * Não existe login do Last.fm aqui: o que a pessoa está ouvindo é dado
 * público, e a leitura pede só a chave do servidor. É justamente isso que
 * faz ele servir pra todo mundo, enquanto o Spotify atende cinco contas.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/lastfm.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = db()->prepare('SELECT lastfm_user FROM usuarios WHERE id = ?');
    $st->execute([$uid]);
    $nome = (string) ($st->fetchColumn() ?: '');

    $tocando = null;
    $erro = '';
    if ($nome !== '') {
        /* Sem cache: quem abriu esta tela quer saber se funciona AGORA. */
        try { $tocando = lf_tocando($uid, 0); }
        catch (Throwable $e) { $erro = $e->getMessage(); }
    }

    json_saida([
        'ligado'  => $nome !== '',
        'usuario' => $nome,
        'tocando' => $tocando,
        'erro'    => $erro,
    ]);
}

trava('lastfm', 30, 300);
$d = corpo_json();

if (!empty($d['desligar'])) {
    db()->prepare('UPDATE usuarios SET lastfm_user = NULL WHERE id = ?')->execute([$uid]);
    db()->prepare('DELETE FROM spotify_cache WHERE usuario_id = ?')->execute([$uid]);
    json_saida(['ok' => true, 'ligado' => false]);
}

try {
    $r = lf_conferir((string) ($d['usuario'] ?? ''));
} catch (Throwable $e) {
    json_saida(['erro' => $e->getMessage()], 400);
}
if (empty($r['ok'])) json_saida(['erro' => $r['erro']], 400);

db()->prepare('UPDATE usuarios SET lastfm_user = ? WHERE id = ?')
    ->execute([mb_substr($r['nome'], 0, 64), $uid]);
/* O cache é de outra conta agora: guardar seria mostrar a música de quem
   estava antes. */
db()->prepare('DELETE FROM spotify_cache WHERE usuario_id = ?')->execute([$uid]);

json_saida(['ok' => true, 'usuario' => $r['nome'], 'tocando' => lf_tocando($uid, 0)]);
