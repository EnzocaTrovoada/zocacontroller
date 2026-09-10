<?php
/**
 * O feed da página inicial.
 *
 * GET             — os posts, com autor e selos
 * GET ?a=img&id=  — entrega a imagem de um post
 * POST multipart  — publicar (precisa da chave do painel)
 * POST json       — apagar (o autor) ou esconder (admin)
 *
 * ---------------------------------------------------------------------
 * TEXTO DE ESTRANHO NUNCA VIRA HTML.
 *
 * O texto sai daqui como texto e a tela o escreve com textContent. Nada de
 * transformar link em <a> automaticamente: além do risco, feed que fabrica
 * link é feed que atrai quem só quer o link.
 *
 * As regras da imagem são as do som.php e do artistas.php: nome sorteado
 * por nós, tipo lido dos bytes, teto de tamanho, arquivo fora da pasta
 * servida e entregue por este PHP com Content-Type fixo.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

const FEED_DIR    = __DIR__ . '/../feed';
const FEED_BYTES  = 4194304;
const FEED_TEXTO  = 500;
const FEED_PAGINA = 30;

const FEED_TIPOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

function feed_caminho(string $arquivo): string
{
    return FEED_DIR . '/' . basename($arquivo);
}

/* ---------- entregar a imagem ---------- */
if (($_GET['a'] ?? '') === 'img') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); exit; }

    $st = db()->prepare('SELECT arquivo FROM posts WHERE id = ? AND escondido = 0 LIMIT 1');
    $st->execute([$id]);
    $arq = (string) ($st->fetchColumn() ?: '');
    if ($arq === '') { http_response_code(404); exit; }

    $caminho = feed_caminho($arq);
    if (!is_readable($caminho)) { http_response_code(404); exit; }

    $ext  = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    $tipo = array_search($ext, FEED_TIPOS, true) ?: 'application/octet-stream';

    header('Content-Type: ' . $tipo);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: public, max-age=604800');
    header('Access-Control-Allow-Origin: *');
    readfile($caminho);
    exit;
}

cors();

function feed_autor(array $u): array
{
    return [
        'login'    => (string) $u['login'],
        'nome'     => (string) ($u['nome_exibicao'] ?: $u['login']),
        'foto'     => (string) ($u['foto'] ?: ''),
        'artista'  => (int) ($u['selo_artista'] ?? 0),
        'streamer' => (int) ($u['selo_streamer'] ?? 0),
    ];
}

/* ---------- ler o feed ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $lista = [];
    try {
        $antes = (int) ($_GET['antes'] ?? 0);
        $sql = 'SELECT p.id, p.texto, p.arquivo, p.criado_em, p.usuario_id,
                       u.login, u.nome_exibicao, u.foto, u.selo_artista, u.selo_streamer
                  FROM posts p JOIN usuarios u ON u.id = p.usuario_id
                 WHERE p.escondido = 0';
        if ($antes > 0) $sql .= ' AND p.id < ' . $antes;
        $sql .= ' ORDER BY p.id DESC LIMIT ' . FEED_PAGINA;

        foreach (db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $lista[] = [
                'id'        => (int) $p['id'],
                'texto'     => (string) $p['texto'],
                'imagem'    => $p['arquivo'] ? (int) $p['id'] : null,
                'criado_em' => (string) $p['criado_em'],
                'autor'     => feed_autor($p),
            ];
        }
    } catch (Throwable $e) { /* tabela ainda não criada */ }

    header('Cache-Control: public, max-age=30');
    json_saida(['posts' => $lista, 'texto_max' => FEED_TEXTO]);
}

/* ---------- daqui pra baixo precisa de conta ---------- */
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

$ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$ad->execute([$uid]);
$souAdmin = (bool) $ad->fetchColumn();

/* ---------- apagar e esconder ---------- */
/* Pelo cabeçalho, e não por adivinhar dos campos: um post sem texto tem que
   receber "escreva alguma coisa", e não cair no caminho de apagar. */
if (strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== 0) {
    $d    = corpo_json();
    $acao = (string) ($d['acao'] ?? '');
    $id   = (int) ($d['id'] ?? 0);
    if ($id <= 0) json_saida(['erro' => 'Falta dizer qual post.'], 400);

    $st = db()->prepare('SELECT usuario_id, arquivo FROM posts WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) json_saida(['erro' => 'Esse post não existe mais.'], 404);

    $meu = (int) $p['usuario_id'] === $uid;
    if (!$meu && !$souAdmin) json_saida(['erro' => 'Esse post não é seu.'], 403);

    if ($acao === 'apagar') {
        if ($p['arquivo']) @unlink(feed_caminho((string) $p['arquivo']));
        db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        json_saida(['ok' => true]);
    }

    /* Esconder guarda o post: às vezes o certo é tirar do ar e ainda poder
       olhar depois com calma. */
    if ($acao === 'esconder' && $souAdmin) {
        db()->prepare('UPDATE posts SET escondido = 1 WHERE id = ?')->execute([$id]);
        json_saida(['ok' => true]);
    }

    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* ---------- publicar ---------- */
trava('post', 12, 3600);

$texto = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) ($_POST['texto'] ?? '')));
$texto = mb_substr($texto, 0, FEED_TEXTO);
if ($texto === '') json_saida(['erro' => 'Escreva alguma coisa.'], 400);

/* Um minuto entre um post e outro da MESMA conta. O limite por hora já
   existe; este é contra o dedo nervoso no botão. */
$st = db()->prepare('SELECT COUNT(*) FROM posts WHERE usuario_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
$st->execute([$uid]);
if ((int) $st->fetchColumn() > 0) {
    json_saida(['erro' => 'Espere um minuto antes de postar de novo.'], 429);
}

$arquivo = null;
if (!empty($_FILES['imagem']) && (int) ($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $f = $_FILES['imagem'];
    if ((int) $f['size'] > FEED_BYTES) json_saida(['erro' => 'A imagem pode ter no máximo 4 MB.'], 400);
    if (!is_uploaded_file($f['tmp_name'])) json_saida(['erro' => 'Arquivo inválido.'], 400);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($f['tmp_name']);
    if (!isset(FEED_TIPOS[$mime])) json_saida(['erro' => 'Vale JPG, PNG, WEBP ou GIF.'], 400);

    if (!pasta_privada(FEED_DIR)) {
        json_saida(['erro' => 'Não consegui guardar a imagem aqui no servidor.'], 500);
    }

    $arquivo = bin2hex(random_bytes(16)) . '.' . FEED_TIPOS[$mime];
    if (!move_uploaded_file($f['tmp_name'], feed_caminho($arquivo))) {
        json_saida(['erro' => 'Não consegui guardar a imagem aqui no servidor.'], 500);
    }
}

db()->prepare('INSERT INTO posts (usuario_id, texto, arquivo) VALUES (?, ?, ?)')
    ->execute([$uid, $texto, $arquivo]);

json_saida(['ok' => true]);
