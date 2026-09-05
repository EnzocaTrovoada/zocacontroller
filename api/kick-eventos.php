<?php
/**
 * O webhook do Kick.
 *
 * Aqui chegam chat, follow e assinatura do Kick. É a diferença de arquitetura
 * em relação à Twitch: lá a ponte lê o chat sozinha por uma conexão anônima
 * dentro do OBS; aqui o Kick ENTREGA. Pra hospedagem compartilhada isso é
 * melhor — webhook não precisa de processo longo nem de ficar perguntando.
 *
 * ---------------------------------------------------------------------
 * ESTA URL É PÚBLICA E QUALQUER UM PODE DESCOBRIR.
 *
 * A única coisa que separa um evento do Kick de um evento inventado por um
 * estranho é a assinatura. Ela é RSA-SHA256 com a chave PÚBLICA do Kick — não
 * existe segredo compartilhado aqui, e quem disser pra usar hash_hmac está
 * falando de outra plataforma.
 *
 * Três defesas, nesta ordem, e nenhuma delas é opcional:
 *   1. assinatura confere               (é mesmo o Kick?)
 *   2. o carimbo de hora é recente      (não é um POST velho reenviado?)
 *   3. este id de mensagem é inédito    (não é o mesmo evento duas vezes?)
 *
 * A 2 e a 3 a doc do Kick não pede. Sem elas, um POST legítimo capturado uma
 * vez pode ser reenviado pra sempre: a assinatura continua matematicamente
 * válida. E a própria reentrega do Kick, que a doc não descreve, viraria sub
 * em dobro no subathon de quem transmite.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/eventos.php';
require_once __DIR__ . '/lib/kick.php';

/* O corpo CRU, antes de qualquer outra coisa. Reserializar (json_encode de um
   json_decode) muda espaçamento, ordem e escapes — e aí a assinatura nunca
   mais bate. E $_POST fica vazio: o Kick manda JSON, não formulário. */
$corpo = file_get_contents('php://input');

function kick_cabecalho(string $nome): string
{
    $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));
    if (isset($_SERVER[$chave])) return (string) $_SERVER[$chave];

    /* Alguns servidores só entregam por getallheaders(), e HTTP não garante a
       caixa das letras do nome. */
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $nome) === 0) return (string) $v;
        }
    }
    return '';
}

function kick_recusa(int $codigo = 403): void
{
    /* Sem dizer o motivo: explicar a quem forjou o que faltou na forja é
       ensinar a forjar melhor. */
    http_response_code($codigo);
    exit;
}

/* ---------- 1. é mesmo o Kick? ---------- */

$msgId = kick_cabecalho('Kick-Event-Message-Id');
$ts    = kick_cabecalho('Kick-Event-Message-Timestamp');
$sigB64 = kick_cabecalho('Kick-Event-Signature');
$tipo  = kick_cabecalho('Kick-Event-Type');

if ($msgId === '' || $ts === '' || $sigB64 === '') kick_recusa();

$sig = base64_decode($sigB64, true);   /* estrito: lixo vira false, não vira bytes */
if ($sig === false) kick_recusa();

$pem = kick_chave_publica();
if ($pem === null) kick_recusa(500);   /* problema nosso, não evento forjado */

$pub = openssl_pkey_get_public($pem);
if ($pub === false) kick_recusa(500);

/* A ordem e os pontos vêm da doc: id . carimbo . corpo cru.
   O === 1 é obrigatório: openssl_verify devolve 1 válido, 0 inválido e -1
   erro — e -1 passaria num if comum. É o bug clássico que abre a porta. */
if (openssl_verify($msgId . '.' . $ts . '.' . $corpo, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
    kick_recusa();
}

/* ---------- 2. é recente? ---------- */

$quando = strtotime($ts);
if (!$quando || abs(time() - $quando) > 300) kick_recusa();

/* ---------- 3. é inédito? ---------- */

try {
    db()->prepare('INSERT INTO kick_recebidos (message_id) VALUES (?)')->execute([$msgId]);
} catch (PDOException $e) {
    /* 23000 = já existe. Repetido não é erro: é o Kick reentregando, ou
       alguém repetindo. Nos dois casos a resposta certa é "ok, e não faço de
       novo" — 200, senão o Kick continua tentando pra sempre. */
    if ($e->getCode() === '23000') { http_response_code(200); exit; }
    throw $e;
}

/* Daqui pra frente o evento é legítimo. A resposta sai agora e o trabalho
   continua sem a plataforma esperando: o Kick não documenta quanto tempo
   espera antes de considerar falha, e é barato não descobrir do jeito ruim. */
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
if (function_exists('litespeed_finish_request')) litespeed_finish_request();
elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

/* ------------------------------------------------------------------ *
 *  O evento em si
 * ------------------------------------------------------------------ */

$d = json_decode($corpo, true);
if (!is_array($d)) exit;

$usuario_id = kick_dono_do_evento($d);
if (!$usuario_id) exit;    /* evento de um canal que não é de ninguém aqui */

/* O id da mensagem vira a chave do evento: é o que impede o mesmo sub de
   contar duas vezes no subathon se o Kick reentregar. */
kick_tratar($usuario_id, $tipo, $d, $msgId);

/* Faxina de vez em quando: os ids guardados só servem dentro da janela de
   frescor. Guardar pra sempre seria juntar lixo sem ninguém ter pedido. */
if (random_int(1, 200) === 1) {
    db()->exec('DELETE FROM kick_recebidos WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}
