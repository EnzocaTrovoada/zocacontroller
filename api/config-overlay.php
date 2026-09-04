<?php
/**
 * Modo B: o overlay no OBS pergunta "mudou?" a cada 10 s com um link curto e fixo.
 * A chave pública aparece em print, em VOD e em tutorial — por isso ela SÓ LÊ.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/contagem.php';

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

$config = json_decode($perfil['config'], true) ?: [];

/* META AUTOMÁTICA.
   O número não é digitado: vem da Twitch. O overlay nunca fala com ela — ele
   pergunta aqui, e aqui a resposta fica guardada por um minuto, senão cada
   batida do OBS viraria uma chamada.
   Se a Twitch não responder, contagem() devolve o último número conhecido em
   vez de zero: uma meta que despenca no meio da live é pior que uma parada. */
$fonte = (string) ($config['fonte'] ?? 'manual');
if ($perfil['tipo'] === 'meta' && $fonte !== 'manual') {
    $auto = contagem((int) $perfil['usuario_id'], $fonte);
    if ($auto !== null) $config['atual'] = $auto;
}

/* ETag: quase toda resposta vira um 304 de poucos bytes. O polling sai de graça.
   O número automático entra na conta — sem ele o 304 devolveria o valor velho
   pra sempre e a meta ficaria congelada, justo a que deveria se mexer sozinha. */
$etag = '"' . md5($perfil['atualizado_em'] . '|' . json_encode($recursos)
                  . '|' . (string) ($config['atual'] ?? '')) . '"';
header('ETag: ' . $etag);
header('Cache-Control: no-cache, must-revalidate');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

json_saida([
    'tipo'     => $perfil['tipo'],
    'config'   => $config,
    'recursos' => $recursos,
]);
