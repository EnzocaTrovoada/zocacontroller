<?php
/**
 * Os sons que o streamer manda pro alerta.
 *
 * POST multipart  — sobe um arquivo   (precisa da chave do painel)
 * GET             — lista os que existem
 * GET ?a=tocar&k= — entrega o arquivo pro overlay dentro do OBS
 * POST acao=apagar
 *
 * ---------------------------------------------------------------------
 * ACEITAR ARQUIVO DE ESTRANHO É A COISA MAIS PERIGOSA QUE UM SITE FAZ.
 *
 * As regras aqui, e o motivo de cada uma:
 *
 *   O nome do arquivo é SORTEADO por nós. O nome que veio do computador da
 *   pessoa nunca vira caminho — é por aí que se grava "alerta.php" numa pasta
 *   servida e se ganha o servidor inteiro.
 *
 *   O tipo é decidido pelo CONTEÚDO, não pela extensão. Qualquer um renomeia
 *   um .php pra .mp3; ninguém renomeia os bytes de um MP3 pra dentro de um
 *   PHP e continua sendo um MP3 tocável.
 *
 *   O arquivo é entregue por ESTE PHP, com Content-Type fixo de áudio e
 *   Content-Disposition de anexo. Mesmo que algo escape das duas regras
 *   acima, o navegador não vai executar como página.
 *
 *   Tem teto de tamanho e de quantidade. Sem teto, uma conta sozinha enche o
 *   disco da hospedagem e derruba o serviço de todo mundo.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

/* Fora da pasta servida: mesmo com o servidor mal configurado, nada aqui
   dentro é alcançável por URL direta. */
const SONS_DIR    = __DIR__ . '/../sons';
const SONS_MAX    = 12;                  /* por streamer */
const SONS_BYTES  = 1048576;             /* 1 MB — alerta é sopro, não música */

/* O que dá pra tocar num navegador, com a assinatura de bytes de cada um. */
const SONS_TIPOS = [
    'audio/mpeg' => 'mp3',
    'audio/mp3'  => 'mp3',
    'audio/ogg'  => 'ogg',
    'audio/wav'  => 'wav',
    'audio/webm' => 'webm',
    'audio/x-wav'      => 'wav',
    'audio/wave'       => 'wav',
    'application/ogg'  => 'ogg',
];

function som_caminho(string $arquivo): string
{
    /* basename corta qualquer tentativa de ../ que tenha chegado até aqui. */
    return SONS_DIR . '/' . basename($arquivo);
}

/* ---------- entregar o arquivo pro overlay ---------- */
if (($_GET['a'] ?? '') === 'tocar') {
    $chave = (string) ($_GET['k'] ?? '');
    $id    = (int) ($_GET['id'] ?? 0);

    /* A chave é a PÚBLICA do overlay, a mesma do config-overlay.php: a fonte
       do OBS não tem, e não deve ter, a chave do painel. */
    if (!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $chave) || $id <= 0) {
        http_response_code(400); exit;
    }

    $st = db()->prepare(
        'SELECT s.arquivo, s.tipo FROM sons s
           JOIN perfis p ON p.usuario_id = s.usuario_id
          WHERE p.chave_publica = ? AND s.id = ? LIMIT 1'
    );
    $st->execute([$chave, $id]);
    $s = $st->fetch();
    if (!$s) { http_response_code(404); exit; }

    $caminho = som_caminho((string) $s['arquivo']);
    if (!is_readable($caminho)) { http_response_code(404); exit; }

    /* Content-Type nosso, e nunca o que veio no upload. Attachment por cima:
       duas voltas na mesma chave, porque a chave é a porta do servidor. */
    header('Content-Type: ' . ((string) $s['tipo'] ?: 'audio/mpeg'));
    header('Content-Disposition: attachment');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');
    readfile($caminho);
    exit;
}

/* ---------- daqui pra baixo é o dono, com a chave do painel ---------- */
cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

function som_lista(int $uid): array
{
    $st = db()->prepare('SELECT id, nome, bytes FROM sons WHERE usuario_id = ? ORDER BY id');
    $st->execute([$uid]);
    return array_map(fn($s) => [
        'id'    => (int) $s['id'],
        'nome'  => (string) $s['nome'],
        'bytes' => (int) $s['bytes'],
    ], $st->fetchAll());
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_saida(['sons' => som_lista($uid), 'max' => SONS_MAX, 'bytes' => SONS_BYTES]);
}

/* ---------- apagar ---------- */
if (empty($_FILES['som'])) {
    $d = corpo_json();
    if (($d['acao'] ?? '') !== 'apagar') json_saida(['erro' => 'Ação desconhecida.'], 400);

    $st = db()->prepare('SELECT arquivo FROM sons WHERE id = ? AND usuario_id = ?');
    $st->execute([(int) ($d['id'] ?? 0), $uid]);
    $arq = $st->fetchColumn();
    if ($arq) {
        @unlink(som_caminho((string) $arq));
        db()->prepare('DELETE FROM sons WHERE id = ? AND usuario_id = ?')
            ->execute([(int) $d['id'], $uid]);
    }
    json_saida(['ok' => true, 'sons' => som_lista($uid)]);
}

/* ---------- subir ---------- */
trava('som', 20, 3600);

$f = $_FILES['som'];
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    /* O erro do PHP é numérico e não diz nada pra quem não é programador. */
    $motivo = match ((int) ($f['error'] ?? -1)) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Esse arquivo é grande demais.',
        UPLOAD_ERR_NO_FILE => 'Você não escolheu nenhum arquivo.',
        default => 'O arquivo não chegou inteiro. Tente de novo.',
    };
    json_saida(['erro' => $motivo], 400);
}

if ((int) $f['size'] > SONS_BYTES) {
    json_saida(['erro' => 'O som tem que ter no máximo 1 MB. Alerta é um toque curto, não uma música.'], 400);
}

/* is_uploaded_file: garante que o caminho veio mesmo de um upload desta
   requisição, e não de um caminho qualquer do disco montado por quem chamou. */
if (!is_uploaded_file($f['tmp_name'])) json_saida(['erro' => 'Arquivo inválido.'], 400);

$st = db()->prepare('SELECT COUNT(*) FROM sons WHERE usuario_id = ?');
$st->execute([$uid]);
if ((int) $st->fetchColumn() >= SONS_MAX) {
    json_saida(['erro' => 'Você já tem ' . SONS_MAX . ' sons. Apague um pra subir outro.'], 400);
}

/* O TIPO SAI DOS BYTES, NÃO DA EXTENSÃO.
   Renomear um .php pra .mp3 é trivial; fazer um arquivo que o finfo leia
   como áudio E que o servidor execute como PHP, não. */
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string) $finfo->file($f['tmp_name']);
if (!isset(SONS_TIPOS[$mime])) {
    json_saida(['erro' => 'Isso não parece um arquivo de som. Vale MP3, OGG, WAV ou WEBM.'], 400);
}

if (!is_dir(SONS_DIR)) @mkdir(SONS_DIR, 0755, true);
if (!is_dir(SONS_DIR) || !is_writable(SONS_DIR)) {
    json_saida(['erro' => 'Não consegui guardar o arquivo aqui no servidor.'], 500);
}

/* Nome sorteado. O nome que veio do computador da pessoa não encosta no
   disco — ele vira só o rótulo na tela, e rótulo não é caminho. */
$arquivo = bin2hex(random_bytes(16)) . '.' . SONS_TIPOS[$mime];
if (!move_uploaded_file($f['tmp_name'], som_caminho($arquivo))) {
    json_saida(['erro' => 'Não consegui guardar o arquivo aqui no servidor.'], 500);
}

$nome = trim((string) ($_POST['nome'] ?? ''));
if ($nome === '') $nome = pathinfo((string) $f['name'], PATHINFO_FILENAME);
$nome = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $nome), 0, 60) ?: 'som';

db()->prepare('INSERT INTO sons (usuario_id, nome, arquivo, bytes, tipo) VALUES (?, ?, ?, ?, ?)')
    ->execute([$uid, $nome, $arquivo, (int) $f['size'], $mime]);

json_saida(['ok' => true, 'sons' => som_lista($uid)]);
