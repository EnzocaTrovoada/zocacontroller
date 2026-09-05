<?php
/**
 * Modo B: o overlay no OBS pergunta "mudou?" a cada 10 s com um link curto e fixo.
 * A chave pública aparece em print, em VOD e em tutorial — por isso ela SÓ LÊ.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/contagem.php';
require_once __DIR__ . '/lib/eventos.php';
require_once __DIR__ . '/lib/spotify.php';

header('Access-Control-Allow-Origin: *');   // o overlay roda dentro do OBS

/* SEM ESTA LINHA, OS DOIS CABEÇALHOS ABAIXO SÃO INVISÍVEIS PRO OVERLAY.
   Resposta de outra origem só entrega ao JavaScript os cabeçalhos da lista
   curta do CORS, e nem Date nem ETag estão nela. Faltando o Expose, o
   r.headers.get('Date') do overlay voltava null e a sincronia de relógio do
   subathon nunca rodou uma vez sequer: o cronômetro seguia o relógio da
   máquina de quem transmite — justamente o que ele tentava não fazer. */
header('Access-Control-Expose-Headers: ETag, Date');

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
    if ($auto !== null) {
        $config['atual'] = $auto;
        /* A meta de viewers vive de saber quantos estao assistindo AGORA.
           Aproveito a consulta que ja foi feita em vez de pedir de novo. */
        if ($fonte === 'viewers') viewers_meta((int) $perfil['usuario_id'], $auto);
    }
}

/* O FEED.

   Os eventos vao no corpo da resposta, e nao dentro da config: config e o
   que a PESSOA escolheu, e isso aqui muda sozinho. Misturar os dois faria
   cada sub novo parecer uma edicao do overlay. */
/* A MUSICA vai no corpo, como os eventos: ela muda sozinha, e config e o que
   a PESSOA escolheu. Junto, cada troca de faixa pareceria uma edicao. */
$musica = null;
if ($perfil['tipo'] === 'musica') {
    try { $musica = sp_tocando((int) $perfil['usuario_id']); } catch (Throwable $e) { $musica = null; }
}

$eventos = null;
if ($perfil['tipo'] === 'feed') {
    $eventos = evento_recentes((int) $perfil['usuario_id'], max(1, min(30, (int) ($config['cmax'] ?? 8))));
} elseif ($perfil['tipo'] === 'alerta') {
    /* Poucos: o alerta mostra um de cada vez e a consulta é de 15 em 15
       segundos. Mais que isso viraria fila do dia inteiro se a fonte ficasse
       um tempo fora do ar. */
    $eventos = evento_recentes((int) $perfil['usuario_id'], 12);
}

/* O ENDEREÇO DO SOM PRÓPRIO.

   Vai montado daqui porque quem sabe onde a API mora é o servidor, não a
   fonte dentro do OBS. E vai só o endereço de tocar, com a chave PÚBLICA do
   overlay: a fonte não tem a chave do painel, e não deveria ter. */
$som = null;
if ($perfil['tipo'] === 'alerta' && !empty($config['asomid'])) {
    $som = rtrim(cfg()['api_base'] ?? '', '/')
         . '/som.php?a=tocar&k=' . rawurlencode($chave)
         . '&id=' . (int) $config['asomid'];
}

/* ETag: quase toda resposta vira um 304 de poucos bytes. O polling sai de graça.
   O número automático entra na conta — sem ele o 304 devolveria o valor velho
   pra sempre e a meta ficaria congelada, justo a que deveria se mexer sozinha. */
$etag = '"' . md5($perfil['atualizado_em'] . '|' . json_encode($recursos)
                  . '|' . (string) ($config['atual'] ?? '')
                  . '|' . ($eventos === null ? '' : md5(json_encode($eventos)))
                  . '|' . ($musica === null ? '' : md5(json_encode($musica)))
                  . '|' . (string) $som) . '"';
header('ETag: ' . $etag);
header('Cache-Control: no-cache, must-revalidate');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

json_saida([
    'tipo'     => $perfil['tipo'],
    'config'   => $config,
    'eventos'  => $eventos,
    'musica'   => $musica,
    'som'      => $som,
    'recursos' => $recursos,
]);
