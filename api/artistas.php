<?php
/**
 * A galeria de artistas.
 *
 * GET              — os aprovados, pra montar a galeria
 * GET ?a=obra&id=  — entrega a imagem
 * POST multipart   — inscrição, aberta a qualquer um
 * POST json        — moderação (só admin)
 *
 * ---------------------------------------------------------------------
 * O SELO "SEM IA" É A PALAVRA DE UMA PESSOA, NÃO DE UM ALGORITMO.
 *
 * Detector de imagem gerada erra dos dois lados, e errar contra um artista
 * humano é pior do que deixar passar. Então: o artista declara, manda um
 * arquivo de processo (rascunho, PSD, timelapse) que só o admin vê, e o
 * admin olha e decide. O texto do site diz isso com todas as letras.
 *
 * As regras de upload são as mesmas do som.php, pelos mesmos motivos: nome
 * sorteado por nós, tipo lido dos bytes, teto de tamanho e de quantidade,
 * arquivo fora da pasta servida e entregue por este PHP.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

const ARTE_DIR        = __DIR__ . '/../arte';
const ARTE_MAX        = 5;
const ARTE_BYTES      = 4194304;
const ARTE_PROC_BYTES = 16777216;
const ARTE_PENDENTES  = 80;

const ARTE_TIPOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

/* O processo pode ser vídeo ou arquivo de programa de desenho. Nada de SVG
   nem de tipo que o finfo não reconheça: só o admin baixa isso, mas baixar
   também é abrir. */
const ARTE_TIPOS_PROC = [
    'image/jpeg'                => 'jpg',
    'image/png'                 => 'png',
    'image/webp'                => 'webp',
    'image/gif'                 => 'gif',
    'video/mp4'                 => 'mp4',
    'video/webm'                => 'webm',
    'video/quicktime'           => 'mov',
    'image/vnd.adobe.photoshop' => 'psd',
    'image/x-psd'               => 'psd',
    'application/x-photoshop'   => 'psd',
    'application/zip'           => 'zip',
];

function arte_caminho(string $arquivo): string
{
    return ARTE_DIR . '/' . basename($arquivo);
}

/* ---------- entregar uma imagem ---------- */
if (($_GET['a'] ?? '') === 'obra') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); exit; }

    $st = db()->prepare(
        'SELECT o.arquivo, o.processo, a.estado
           FROM artista_obras o JOIN artistas a ON a.id = o.artista_id
          WHERE o.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) { http_response_code(404); exit; }

    /* Pendente e arquivo de processo só existem pra quem modera.

       Uma <img> não manda cabeçalho, então a chave do painel não serve aqui.
       O que serve é um link assinado de 15 minutos, que a listagem do admin
       devolve junto de cada obra. */
    $publica = (int) $o['processo'] === 0 && $o['estado'] === 'aprovado';
    if (!$publica) {
        require_once __DIR__ . '/lib/seguranca.php';
        if (link_verificar((string) ($_GET['t'] ?? '')) !== 'obra:' . $id) {
            http_response_code(404); exit;
        }
    }

    $caminho = arte_caminho((string) $o['arquivo']);
    if (!is_readable($caminho)) { http_response_code(404); exit; }

    $ext  = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    $tipo = array_search($ext, ARTE_TIPOS_PROC, true) ?: 'application/octet-stream';

    /* Imagem vai inline porque precisa aparecer numa <img>; o resto vai como
       anexo. Com nosniff e tipo da nossa lista, inline não executa nada. */
    header('Content-Type: ' . $tipo);
    header('Content-Disposition: ' . (isset(ARTE_TIPOS[$tipo]) ? 'inline' : 'attachment'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: ' . ($publica ? 'public, max-age=604800' : 'private, no-store'));
    if ($publica) header('Access-Control-Allow-Origin: *');
    readfile($caminho);
    exit;
}

cors();

/* ---------- a galeria ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $lista = [];
    try {
        $artistas = db()->query(
            "SELECT id, nome, arroba, link, bio, sem_ia
               FROM artistas WHERE estado = 'aprovado' ORDER BY criado_em DESC LIMIT 60"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($artistas) {
            $ids = array_column($artistas, 'id');
            $vaz = implode(',', array_fill(0, count($ids), '?'));
            $ob  = db()->prepare(
                "SELECT id, artista_id, titulo FROM artista_obras
                  WHERE processo = 0 AND artista_id IN ($vaz) ORDER BY artista_id, ordem, id"
            );
            $ob->execute($ids);

            $porArtista = [];
            foreach ($ob->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $porArtista[(int) $o['artista_id']][] = [
                    'id' => (int) $o['id'], 'titulo' => $o['titulo'],
                ];
            }

            foreach ($artistas as $a) {
                $lista[] = [
                    'id'     => (int) $a['id'],
                    'nome'   => (string) $a['nome'],
                    'arroba' => $a['arroba'],
                    'link'   => $a['link'],
                    'bio'    => $a['bio'],
                    'sem_ia' => (int) $a['sem_ia'],
                    'obras'  => $porArtista[(int) $a['id']] ?? [],
                ];
            }
        }
    } catch (Throwable $e) { /* tabela ainda não criada */ }

    header('Cache-Control: public, max-age=120');
    json_saida(['artistas' => $lista, 'max' => ARTE_MAX]);
}

/* ---------- moderação ---------- */
if (empty($_FILES['obras'])) {
    $quem = exige_painel();
    $ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
    $ad->execute([(int) $quem['usuario_id']]);
    if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

    $d    = corpo_json();
    $acao = (string) ($d['acao'] ?? '');
    $id   = (int) ($d['id'] ?? 0);

    $apagaArquivos = function (int $artista) {
        $st = db()->prepare('SELECT arquivo FROM artista_obras WHERE artista_id = ?');
        $st->execute([$artista]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $arq) @unlink(arte_caminho((string) $arq));
    };

    if ($acao === 'lista') {
        require_once __DIR__ . '/lib/seguranca.php';

        $todos = db()->query(
            "SELECT id, nome, arroba, link, bio, contato, estado, sem_ia, criado_em
               FROM artistas ORDER BY estado = 'pendente' DESC, criado_em DESC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);

        $obras = [];
        foreach (db()->query('SELECT id, artista_id, titulo, processo FROM artista_obras ORDER BY ordem, id')
                     ->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $obras[(int) $o['artista_id']][] = [
                'id'       => (int) $o['id'],
                'titulo'   => $o['titulo'],
                'processo' => (int) $o['processo'],
                't'        => link_assinar('obra:' . (int) $o['id'], 900),
            ];
        }
        foreach ($todos as &$a) {
            $a['id']     = (int) $a['id'];
            $a['sem_ia'] = (int) $a['sem_ia'];
            $a['obras']  = $obras[$a['id']] ?? [];
        }
        json_saida(['artistas' => $todos]);
    }

    if ($acao === 'aprovar') {
        $selo = empty($d['sem_ia']) ? 0 : 1;
        db()->prepare(
            "UPDATE artistas SET estado = 'aprovado', sem_ia = ?,
                    verificado_em = CASE WHEN ? = 1 THEN NOW() ELSE NULL END
              WHERE id = ?"
        )->execute([$selo, $selo, $id]);

        /* Aprovado com selo e com conta: o selo aparece também no feed, sem
           precisar ligar de novo na outra tela. */
        if ($selo) {
            db()->prepare(
                'UPDATE usuarios SET selo_artista = 1
                  WHERE id = (SELECT usuario_id FROM artistas WHERE id = ?)'
            )->execute([$id]);
        }
        json_saida(['ok' => true]);
    }

    if ($acao === 'recusar') {
        /* O cadastro fica (pra lembrar que já passou por aqui), os arquivos
           não: recusado ocupando disco é custo sem uso. */
        $apagaArquivos($id);
        db()->prepare('DELETE FROM artista_obras WHERE artista_id = ?')->execute([$id]);
        db()->prepare("UPDATE artistas SET estado = 'recusado', sem_ia = 0 WHERE id = ?")->execute([$id]);
        json_saida(['ok' => true]);
    }

    if ($acao === 'apagar') {
        $apagaArquivos($id);
        db()->prepare('DELETE FROM artistas WHERE id = ?')->execute([$id]);
        json_saida(['ok' => true]);
    }

    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* ---------- inscrição ---------- */
trava('artista', 5, 3600);

$nome = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) ($_POST['nome'] ?? ''))), 0, 80);
if (mb_strlen($nome) < 2) json_saida(['erro' => 'Escreva o seu nome de artista.'], 400);

$arroba = preg_replace('/[^A-Za-z0-9._-]/', '', ltrim(trim((string) ($_POST['arroba'] ?? '')), '@'));
$arroba = mb_substr($arroba, 0, 64) ?: null;

$link = trim((string) ($_POST['link'] ?? ''));
if ($link !== '' && !preg_match('#^https?://[^\s<>"]{4,190}$#i', $link)) {
    json_saida(['erro' => 'O link precisa começar com http:// ou https://.'], 400);
}
$link = $link ?: null;

$bio     = mb_substr(trim((string) ($_POST['bio'] ?? '')), 0, 240) ?: null;
$contato = mb_substr(trim((string) ($_POST['contato'] ?? '')), 0, 160) ?: null;
$semIa   = empty($_POST['sem_ia']) ? 0 : 1;

/* Quem já tem conta manda a chave junto: é o que permite acender o selo de
   artista no feed quando a inscrição for aprovada. */
$doDono = null;
if (($_SERVER['HTTP_X_CHAVE'] ?? '') !== '') {
    try {
        $q = quem_chama();
        if ($q['tipo'] === 'painel') $doDono = (int) $q['usuario_id'];
    } catch (Throwable $e) { /* chave velha: a inscrição continua valendo */ }
}

$pend = (int) db()->query("SELECT COUNT(*) FROM artistas WHERE estado = 'pendente'")->fetchColumn();
if ($pend >= ARTE_PENDENTES) {
    json_saida(['erro' => 'A fila de inscrições está cheia hoje. Tente de novo amanhã.'], 429);
}

if ($arroba !== null) {
    $st = db()->prepare("SELECT COUNT(*) FROM artistas WHERE arroba = ? AND estado = 'pendente'");
    $st->execute([$arroba]);
    if ((int) $st->fetchColumn() > 0) {
        json_saida(['erro' => 'Já existe uma inscrição sua na fila. Espere a resposta.'], 400);
    }
}

/* multipart com obras[] chega como colunas paralelas: cada campo do $_FILES
   é um array indexado pela posição, não uma lista de arquivos. */
$env = $_FILES['obras'];
$qtd = is_array($env['name'] ?? null) ? count($env['name']) : 0;
if ($qtd < 1) json_saida(['erro' => 'Mande pelo menos uma imagem.'], 400);
if ($qtd > ARTE_MAX) json_saida(['erro' => 'No máximo ' . ARTE_MAX . ' imagens.'], 400);

if (!pasta_privada(ARTE_DIR)) {
    json_saida(['erro' => 'Não consegui guardar as imagens aqui no servidor.'], 500);
}

$finfo   = new finfo(FILEINFO_MIME_TYPE);
$titulos = is_array($_POST['titulos'] ?? null) ? $_POST['titulos'] : [];

/* Confere tudo ANTES de gravar qualquer coisa: metade das imagens no disco
   com o cadastro pela metade é lixo que ninguém vai limpar. */
$aceitos = [];
for ($i = 0; $i < $qtd; $i++) {
    if ((int) ($env['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_saida(['erro' => 'Uma das imagens não chegou inteira. Tente de novo.'], 400);
    }
    if ((int) $env['size'][$i] > ARTE_BYTES) {
        json_saida(['erro' => 'Cada imagem pode ter no máximo 4 MB.'], 400);
    }
    if (!is_uploaded_file($env['tmp_name'][$i])) json_saida(['erro' => 'Arquivo inválido.'], 400);

    $mime = (string) $finfo->file($env['tmp_name'][$i]);
    if (!isset(ARTE_TIPOS[$mime])) {
        json_saida(['erro' => 'Vale JPG, PNG, WEBP ou GIF.'], 400);
    }

    $aceitos[] = [
        'tmp'    => $env['tmp_name'][$i],
        'ext'    => ARTE_TIPOS[$mime],
        'titulo' => mb_substr(trim((string) ($titulos[$i] ?? '')), 0, 120) ?: null,
    ];
}

$proc = null;
if (!empty($_FILES['processo']) && (int) ($_FILES['processo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $p = $_FILES['processo'];
    if ((int) $p['size'] > ARTE_PROC_BYTES) {
        json_saida(['erro' => 'O arquivo de processo pode ter no máximo 16 MB.'], 400);
    }
    if (!is_uploaded_file($p['tmp_name'])) json_saida(['erro' => 'Arquivo inválido.'], 400);

    $mime = (string) $finfo->file($p['tmp_name']);
    if (!isset(ARTE_TIPOS_PROC[$mime])) {
        json_saida(['erro' => 'O processo pode ser imagem, vídeo, PSD ou ZIP.'], 400);
    }
    $proc = ['tmp' => $p['tmp_name'], 'ext' => ARTE_TIPOS_PROC[$mime]];
}

$pdo = db();
$pdo->beginTransaction();
$gravados = [];
try {
    $pdo->prepare(
        'INSERT INTO artistas (nome, arroba, link, bio, contato, sem_ia, usuario_id)
              VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$nome, $arroba, $link, $bio, $contato, $semIa, $doDono]);
    $artistaId = (int) $pdo->lastInsertId();

    $poe = function (array $f, int $ordem, ?string $titulo, int $processo) use ($pdo, $artistaId, &$gravados) {
        $arquivo = bin2hex(random_bytes(16)) . '.' . $f['ext'];
        if (!move_uploaded_file($f['tmp'], arte_caminho($arquivo))) throw new RuntimeException('gravar');
        $gravados[] = $arquivo;
        $pdo->prepare(
            'INSERT INTO artista_obras (artista_id, arquivo, titulo, processo, ordem) VALUES (?, ?, ?, ?, ?)'
        )->execute([$artistaId, $arquivo, $titulo, $processo, $ordem]);
    };

    foreach ($aceitos as $ordem => $f) $poe($f, $ordem, $f['titulo'], 0);
    if ($proc) $poe($proc, 99, null, 1);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($gravados as $arq) @unlink(arte_caminho($arq));
    json_saida(['erro' => 'Não consegui guardar a inscrição. Tente de novo.'], 500);
}

json_saida(['ok' => true]);
