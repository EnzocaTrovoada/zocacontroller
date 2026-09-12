<?php
/**
 * Suporte: a pessoa escreve, você responde, e a resposta chega como aviso.
 *
 * GET                — os chamados de quem está perguntando
 * GET ?a=caixa       — a fila inteira (só admin), com quem é Pro na frente
 * GET ?a=print&id=&t= — o print de um chamado, por link assinado (só admin)
 * POST multipart     — abrir um chamado, com um print opcional
 * POST json          — responder e fechar (só admin)
 *
 * Sem e-mail no meio: a resposta vira notificação, que é o caminho que o
 * site já tem pra falar com alguém.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/notificacoes.php';

const SUP_DIR   = __DIR__ . '/../suporte';
const SUP_BYTES = 4194304;

const SUP_TIPOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

function sup_caminho(string $arquivo): string
{
    return SUP_DIR . '/' . basename($arquivo);
}

/* ---------- o print, só pra quem modera ---------- */
/* Uma <img> não manda cabeçalho, então a chave do painel não serve: o que
   serve é o link assinado que a caixa de entrada devolve junto. */
if (($_GET['a'] ?? '') === 'print') {
    $id = (int) ($_GET['id'] ?? 0);
    if (link_verificar((string) ($_GET['t'] ?? '')) !== 'sup:' . $id) { http_response_code(404); exit; }

    $st = db()->prepare('SELECT arquivo FROM suporte WHERE id = ?');
    $st->execute([$id]);
    $arq = (string) ($st->fetchColumn() ?: '');
    if ($arq === '') { http_response_code(404); exit; }

    $caminho = sup_caminho($arq);
    if (!is_readable($caminho)) { http_response_code(404); exit; }

    $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    header('Content-Type: ' . (array_search($ext, SUP_TIPOS, true) ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($caminho));
    readfile($caminho);
    exit;
}

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

$ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$ad->execute([$uid]);
$souAdmin = (bool) $ad->fetchColumn();

function sup_linha(array $s, bool $comPrint = false): array
{
    return [
        'id'        => (int) $s['id'],
        'assunto'   => (string) $s['assunto'],
        'mensagem'  => (string) $s['mensagem'],
        'estado'    => (string) $s['estado'],
        'resposta'  => $s['resposta'],
        'ha'        => (int) $s['ha'],
        'print'     => ($comPrint && $s['arquivo'])
            ? api_base() . '/suporte.php?a=print&id=' . (int) $s['id']
              . '&t=' . rawurlencode(link_assinar('sup:' . (int) $s['id'], 900))
            : null,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Cache-Control: private, no-store');

    /* ---------- a fila, só admin ---------- */
    if (($_GET['a'] ?? '') === 'caixa') {
        if (!$souAdmin) json_saida(['erro' => 'Não encontrado.'], 404);

        $st = db()->query(
            "SELECT s.*, u.login, TIMESTAMPDIFF(SECOND, s.criado_em, NOW()) AS ha
               FROM suporte s JOIN usuarios u ON u.id = s.usuario_id
              ORDER BY s.estado = 'aberto' DESC, s.criado_em DESC LIMIT 100"
        );

        require_once __DIR__ . '/lib/assinatura.php';
        $fila = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $acesso = acesso_do_usuario((int) $s['usuario_id']);
            $fila[] = array_merge(sup_linha($s, true), [
                'login'   => (string) $s['login'],
                'contato' => $s['contato'],
                /* Suporte pessoal é o que o Pro promete: quem paga vem na frente. */
                'pro'     => $acesso['ativo'] && $acesso['plano'] !== 'gratis',
            ]);
        }

        usort($fila, fn($a, $b) => ($b['estado'] === 'aberto' ? 1 : 0) - ($a['estado'] === 'aberto' ? 1 : 0)
                                ?: ($b['pro'] ? 1 : 0) - ($a['pro'] ? 1 : 0));
        json_saida(['chamados' => $fila]);
    }

    $st = db()->prepare(
        'SELECT *, TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS ha
           FROM suporte WHERE usuario_id = ? ORDER BY id DESC LIMIT 20'
    );
    $st->execute([$uid]);
    json_saida(['chamados' => array_map(fn($s) => sup_linha($s), $st->fetchAll(PDO::FETCH_ASSOC))]);
}

/* ---------- responder e fechar ---------- */
if (strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== 0) {
    if (!$souAdmin) json_saida(['erro' => 'Não encontrado.'], 404);

    $d    = corpo_json();
    $acao = (string) ($d['acao'] ?? '');
    $id   = (int) ($d['id'] ?? 0);

    if ($acao === 'responder') {
        $resposta = trim((string) ($d['resposta'] ?? ''));
        if ($resposta === '') json_saida(['erro' => 'Escreva a resposta.'], 400);

        $st = db()->prepare('SELECT usuario_id, assunto FROM suporte WHERE id = ?');
        $st->execute([$id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) json_saida(['erro' => 'Esse chamado não existe.'], 404);

        db()->prepare("UPDATE suporte SET resposta = ?, estado = 'respondido', respondido_em = NOW() WHERE id = ?")
            ->execute([mb_substr($resposta, 0, 4000), $id]);

        notifica((int) $s['usuario_id'], 'suporte',
            'Respondi o seu chamado: ' . $s['assunto'], '#/suporte', null, 'sup:' . $id);

        json_saida(['ok' => true]);
    }

    if ($acao === 'fechar') {
        db()->prepare("UPDATE suporte SET estado = 'fechado' WHERE id = ?")->execute([$id]);
        json_saida(['ok' => true]);
    }

    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* ---------- abrir um chamado ---------- */
trava('suporte', 3, 3600);

$assunto  = mb_substr(trim((string) ($_POST['assunto'] ?? '')), 0, 80);
$mensagem = mb_substr(trim((string) ($_POST['mensagem'] ?? '')), 0, 2000);
$contato  = mb_substr(trim((string) ($_POST['contato'] ?? '')), 0, 120) ?: null;

if (mb_strlen($assunto) < 3)   json_saida(['erro' => 'Escreva um assunto.'], 400);
if (mb_strlen($mensagem) < 10) json_saida(['erro' => 'Conte o que está acontecendo, com um pouco mais de detalhe.'], 400);

$arquivo = null;
if (!empty($_FILES['print']) && (int) ($_FILES['print']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $f = $_FILES['print'];
    if ((int) $f['size'] > SUP_BYTES) json_saida(['erro' => 'O print pode ter no máximo 4 MB.'], 400);
    if (!is_uploaded_file($f['tmp_name'])) json_saida(['erro' => 'Arquivo inválido.'], 400);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($f['tmp_name']);
    if (!isset(SUP_TIPOS[$mime])) json_saida(['erro' => 'O print precisa ser JPG, PNG, WEBP ou GIF.'], 400);

    if (!pasta_privada(SUP_DIR)) json_saida(['erro' => 'Não consegui guardar o print aqui no servidor.'], 500);

    $arquivo = bin2hex(random_bytes(16)) . '.' . SUP_TIPOS[$mime];
    if (!move_uploaded_file($f['tmp_name'], sup_caminho($arquivo))) {
        json_saida(['erro' => 'Não consegui guardar o print aqui no servidor.'], 500);
    }
}

db()->prepare('INSERT INTO suporte (usuario_id, assunto, mensagem, arquivo, contato) VALUES (?, ?, ?, ?, ?)')
    ->execute([$uid, $assunto, $mensagem, $arquivo, $contato]);

json_saida(['ok' => true]);
