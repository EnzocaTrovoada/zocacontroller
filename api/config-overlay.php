<?php
/**
 * Modo B: o overlay no OBS pergunta "mudou?" a cada 10 s com um link curto e fixo.
 * A chave pública aparece em print, em VOD e em tutorial — por isso ela SÓ LÊ.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/assinatura.php';

header('Access-Control-Allow-Origin: *');   // o overlay roda dentro do OBS

$chave = $_GET['k'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $chave)) {
    json_saida(['erro' => 'Link inválido. Gere um novo no painel.'], 400);
}

$st = db()->prepare(
    'SELECT tipo, config, atualizado_em, usuario_id FROM perfis WHERE chave_publica = ? LIMIT 1'
);
$st->execute([$chave]);
$perfil = $st->fetch();

if (!$perfil) {
    json_saida(['erro' => 'Link inválido. Gere um novo no painel.'], 404);
}

// Quem decide o que está liberado é o servidor. Sempre.
$acesso   = acesso_do_usuario((int) $perfil['usuario_id']);
$recursos = recursos_do_plano($acesso['ativo'] ? $acesso['plano'] : 'gratis');

// ETag: quase toda resposta vira um 304 de poucos bytes. O polling sai de graça.
$etag = '"' . md5($perfil['atualizado_em'] . '|' . json_encode($recursos)) . '"';
header('ETag: ' . $etag);
header('Cache-Control: no-cache, must-revalidate');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

json_saida([
    'tipo'     => $perfil['tipo'],
    'config'   => json_decode($perfil['config'], true),
    'recursos' => $recursos,
]);
