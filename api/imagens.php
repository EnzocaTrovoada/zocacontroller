<?php
/**
 * As imagens pequenas de cada usuário — hoje, o ícone de cada trecho do
 * speedrun.
 *
 * GET                 — lista as suas (precisa da chave do painel)
 * GET ?a=ver&k=&id=   — entrega a imagem pro overlay dentro do OBS
 * POST multipart      — sobe uma e devolve o id
 * POST json           — apagar
 *
 * As regras são as do som.php, pelos mesmos motivos: o nome do arquivo é
 * sorteado por nós, o tipo sai dos bytes e não da extensão, tem teto de
 * tamanho e de quantidade, e o arquivo mora fora da pasta servida.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/imagens.php';

/* ---------- entregar pro overlay ---------- */
if (($_GET['a'] ?? '') === 'ver') {
    $chave = (string) ($_GET['k'] ?? '');
    $id    = (int) ($_GET['id'] ?? 0);

    /* A chave é a PÚBLICA do overlay, a mesma do config-overlay.php: a fonte
       do OBS não tem, e não deve ter, a chave do painel. */
    if (!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $chave) || $id <= 0) { http_response_code(400); exit; }

    $st = db()->prepare(
        'SELECT i.arquivo, i.tipo FROM imagens i
           JOIN perfis p ON p.usuario_id = i.usuario_id
          WHERE p.chave_publica = ? AND i.id = ? LIMIT 1'
    );
    $st->execute([$chave, $id]);
    $i = $st->fetch();
    if (!$i) { http_response_code(404); exit; }

    $caminho = img_caminho((string) $i['arquivo']);
    if (!is_readable($caminho)) { http_response_code(404); exit; }

    header('Content-Type: ' . ((string) $i['tipo'] ?: 'image/png'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: public, max-age=604800');
    header('Access-Control-Allow-Origin: *');
    readfile($caminho);
    exit;
}

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

function img_lista(int $uid): array
{
    try {
        $st = db()->prepare('SELECT id, nome, bytes FROM imagens WHERE usuario_id = ? ORDER BY id DESC');
        $st->execute([$uid]);
    } catch (Throwable $e) {
        return [];
    }

    return array_map(fn($i) => [
        'id'    => (int) $i['id'],
        'nome'  => (string) ($i['nome'] ?? ''),
        'bytes' => (int) $i['bytes'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_saida(['imagens' => img_lista($uid), 'max' => IMG_MAX, 'bytes' => IMG_BYTES]);
}

/* ---------- apagar ---------- */
if (empty($_FILES['img'])) {
    $d = corpo_json();
    if (($d['acao'] ?? '') !== 'apagar') json_saida(['erro' => 'Ação desconhecida.'], 400);

    $st = db()->prepare('SELECT arquivo FROM imagens WHERE id = ? AND usuario_id = ?');
    $st->execute([(int) ($d['id'] ?? 0), $uid]);
    $arq = $st->fetchColumn();
    if ($arq) {
        @unlink(img_caminho((string) $arq));
        db()->prepare('DELETE FROM imagens WHERE id = ? AND usuario_id = ?')->execute([(int) $d['id'], $uid]);
    }
    json_saida(['ok' => true, 'imagens' => img_lista($uid)]);
}

/* ---------- subir ---------- */
trava('imagem', 60, 3600);

$f = $_FILES['img'];
if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_saida(['erro' => 'A imagem não chegou inteira. Tente de novo.'], 400);
}
if ((int) $f['size'] > IMG_BYTES) json_saida(['erro' => 'O ícone pode ter no máximo 256 KB.'], 400);
if (!is_uploaded_file($f['tmp_name'])) json_saida(['erro' => 'Arquivo inválido.'], 400);

$st = db()->prepare('SELECT COUNT(*) FROM imagens WHERE usuario_id = ?');
$st->execute([$uid]);
if ((int) $st->fetchColumn() >= IMG_MAX) {
    json_saida(['erro' => 'Você já tem ' . IMG_MAX . ' imagens. Apague alguma pra subir outra.'], 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string) $finfo->file($f['tmp_name']);
if (!isset(IMG_TIPOS[$mime])) json_saida(['erro' => 'Vale PNG, WEBP, GIF ou JPG.'], 400);

if (!pasta_privada(IMG_DIR)) json_saida(['erro' => 'Não consegui guardar a imagem aqui no servidor.'], 500);

$arquivo = bin2hex(random_bytes(16)) . '.' . IMG_TIPOS[$mime];
if (!move_uploaded_file($f['tmp_name'], img_caminho($arquivo))) {
    json_saida(['erro' => 'Não consegui guardar a imagem aqui no servidor.'], 500);
}

$nome = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) ($_POST['nome'] ?? '')), 0, 60);
if ($nome === '') $nome = mb_substr(pathinfo((string) $f['name'], PATHINFO_FILENAME), 0, 60);

db()->prepare('INSERT INTO imagens (usuario_id, arquivo, nome, bytes, tipo) VALUES (?, ?, ?, ?, ?)')
    ->execute([$uid, $arquivo, $nome, (int) $f['size'], $mime]);

json_saida(['ok' => true, 'id' => (int) db()->lastInsertId(), 'imagens' => img_lista($uid)]);
