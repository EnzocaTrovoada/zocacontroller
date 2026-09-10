<?php
/**
 * Os selos: quais existem, o desenho de cada um, e quem tem qual.
 *
 * GET             — a lista (pública: a tela precisa dela pra desenhar)
 * GET ?a=img&id=  — o desenho de um selo
 * POST multipart  — criar ou trocar o desenho (só admin)
 * POST json       — apagar, dar e tirar (só admin)
 *
 * Dar um selo é decisão de uma pessoa olhando, e é isso que faz o selo
 * valer alguma coisa. Não existe nada automático aqui.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/selos.php';

/* ---------- entregar o desenho ---------- */
if (($_GET['a'] ?? '') === 'img') {
    $id = (int) ($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT arquivo FROM selos WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $arq = (string) ($st->fetchColumn() ?: '');
    if ($arq === '') { http_response_code(404); exit; }

    $caminho = selo_caminho($arq);
    if (!is_readable($caminho)) { http_response_code(404); exit; }

    $ext  = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    $tipo = array_search($ext, SELO_TIPOS, true) ?: 'application/octet-stream';

    /* SVG É O ÚNICO QUE PODE VIRAR PÁGINA.

       Ele é XML e aceita <script> dentro. Servido como anexo e com nosniff,
       o navegador desenha na <img> e não executa nada — mas quem abrir o
       endereço direto baixa em vez de abrir, e é isso que fecha a porta. */
    header('Content-Type: ' . $tipo);
    if ($ext === 'svg') header('Content-Disposition: attachment');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');
    readfile($caminho);
    exit;
}

cors();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Cache-Control: public, max-age=300');
    json_saida(['selos' => selo_lista()]);
}

/* ---------- daqui pra baixo, só admin ---------- */
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

$ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$ad->execute([$uid]);
if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

/* ---------- criar, renomear e desenhar ---------- */
if (strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0) {
    $slug = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) ($_POST['slug'] ?? '')));
    $slug = substr($slug, 0, 24);
    if ($slug === '') json_saida(['erro' => 'O selo precisa de um apelido curto (só letras).'], 400);

    $nome = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 32) ?: $slug;
    $cor  = strtoupper(trim((string) ($_POST['cor'] ?? '')));
    if (!preg_match('/^#[0-9A-F]{6}$/', $cor)) $cor = '#12A150';

    $arquivo = null;
    if (!empty($_FILES['img']) && (int) ($_FILES['img']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $f = $_FILES['img'];
        if ((int) $f['size'] > SELO_BYTES) json_saida(['erro' => 'O desenho pode ter no máximo 256 KB.'], 400);
        if (!is_uploaded_file($f['tmp_name'])) json_saida(['erro' => 'Arquivo inválido.'], 400);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($f['tmp_name']);
        /* O finfo devolve text/xml pra SVG quando o arquivo não abre com a
           declaração; a extensão sozinha não decide, mas aqui ela desempata
           entre dois tipos que ele confunde. */
        if ($mime === 'text/xml' || $mime === 'text/plain') {
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if ($ext === 'svg' && strpos((string) file_get_contents($f['tmp_name'], false, null, 0, 400), '<svg') !== false) {
                $mime = 'image/svg+xml';
            }
        }
        if (!isset(SELO_TIPOS[$mime])) json_saida(['erro' => 'Vale PNG, WEBP, GIF ou SVG.'], 400);

        if (!pasta_privada(SELO_DIR)) json_saida(['erro' => 'Não consegui guardar o desenho.'], 500);

        $arquivo = bin2hex(random_bytes(16)) . '.' . SELO_TIPOS[$mime];
        if (!move_uploaded_file($f['tmp_name'], selo_caminho($arquivo))) {
            json_saida(['erro' => 'Não consegui guardar o desenho.'], 500);
        }

        $st = db()->prepare('SELECT arquivo FROM selos WHERE slug = ?');
        $st->execute([$slug]);
        $velho = (string) ($st->fetchColumn() ?: '');
        if ($velho !== '') @unlink(selo_caminho($velho));
    }

    if ($arquivo === null) {
        db()->prepare(
            'INSERT INTO selos (slug, nome, cor, ordem) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome), cor = VALUES(cor), ordem = VALUES(ordem)'
        )->execute([$slug, $nome, $cor, (int) ($_POST['ordem'] ?? 0)]);
    } else {
        db()->prepare(
            'INSERT INTO selos (slug, nome, cor, arquivo, ordem) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome), cor = VALUES(cor),
                  arquivo = VALUES(arquivo), ordem = VALUES(ordem)'
        )->execute([$slug, $nome, $cor, $arquivo, (int) ($_POST['ordem'] ?? 0)]);
    }

    json_saida(['ok' => true, 'selos' => selo_lista()]);
}

$d    = corpo_json();
$acao = (string) ($d['acao'] ?? '');

if ($acao === 'apagar') {
    $st = db()->prepare('SELECT arquivo FROM selos WHERE id = ?');
    $st->execute([(int) ($d['id'] ?? 0)]);
    $arq = (string) ($st->fetchColumn() ?: '');
    if ($arq !== '') @unlink(selo_caminho($arq));

    db()->prepare('DELETE FROM usuario_selos WHERE selo_id = ?')->execute([(int) $d['id']]);
    db()->prepare('DELETE FROM selos WHERE id = ?')->execute([(int) $d['id']]);
    json_saida(['ok' => true, 'selos' => selo_lista()]);
}

if ($acao === 'tirar_desenho') {
    $st = db()->prepare('SELECT arquivo FROM selos WHERE id = ?');
    $st->execute([(int) ($d['id'] ?? 0)]);
    $arq = (string) ($st->fetchColumn() ?: '');
    if ($arq !== '') @unlink(selo_caminho($arq));

    db()->prepare('UPDATE selos SET arquivo = NULL WHERE id = ?')->execute([(int) $d['id']]);
    json_saida(['ok' => true, 'selos' => selo_lista()]);
}

/* ---------- dar e tirar de alguém ---------- */
if ($acao === 'de') {
    $alvo = (int) ($d['usuario_id'] ?? 0);
    $tem  = selos_de([$alvo]);
    json_saida(['selos' => $tem[$alvo] ?? []]);
}

if ($acao === 'marcar') {
    $alvo  = (int) ($d['usuario_id'] ?? 0);
    $selo  = (int) ($d['selo_id'] ?? 0);
    if ($alvo <= 0 || $selo <= 0) json_saida(['erro' => 'Falta dizer quem e qual.'], 400);

    if (empty($d['dar'])) {
        db()->prepare('DELETE FROM usuario_selos WHERE usuario_id = ? AND selo_id = ?')->execute([$alvo, $selo]);
    } else {
        db()->prepare('INSERT IGNORE INTO usuario_selos (usuario_id, selo_id) VALUES (?, ?)')->execute([$alvo, $selo]);
    }
    json_saida(['ok' => true]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
