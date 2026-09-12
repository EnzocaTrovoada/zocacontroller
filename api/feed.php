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
require_once __DIR__ . '/lib/selos.php';
require_once __DIR__ . '/lib/notificacoes.php';
require_once __DIR__ . '/lib/aovivo.php';

/* QUEM CONTA O TEMPO É O BANCO.

   O 'criado_em' vem de um NOW() do MySQL, sem fuso escrito nele. A tela
   remontava a data com esses números como se fossem do relógio de quem
   está olhando — e como o servidor está num fuso e o Brasil está noutro,
   um post de agora aparecia como "3 h". Mandar os SEGUNDOS já contados
   pelo banco acaba com a conta dos dois lados. */
const FEED_HA = 'TIMESTAMPDIFF(SECOND, %s.criado_em, NOW())';

const FEED_DIR      = __DIR__ . '/../feed';
const FEED_BYTES    = 4194304;
const FEED_TEXTO    = 500;
const FEED_COMENTA  = 300;
const FEED_PAGINA   = 30;
const FEED_FOTO     = 1048576;

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

/* ---------- entregar a foto de perfil ---------- */
/* Pelo login, e não pelo id da conta: o login já aparece em todo post, e
   assim nenhum identificador novo passa a existir do lado de fora. */
if (($_GET['a'] ?? '') === 'foto') {
    $login = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_GET['login'] ?? ''));
    if ($login === '') { http_response_code(400); exit; }

    $st = db()->prepare('SELECT foto_propria FROM usuarios WHERE login = ? LIMIT 1');
    $st->execute([$login]);
    $arq = (string) ($st->fetchColumn() ?: '');
    if ($arq === '') { http_response_code(404); exit; }

    $caminho = feed_caminho($arq);
    if (!is_readable($caminho)) { http_response_code(404); exit; }

    $ext  = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    $tipo = array_search($ext, FEED_TIPOS, true) ?: 'application/octet-stream';

    header('Content-Type: ' . $tipo);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($caminho));
    /* Curto: a pessoa troca a foto e quer ver a nova, não a de ontem. */
    header('Cache-Control: public, max-age=600');
    header('Access-Control-Allow-Origin: *');
    readfile($caminho);
    exit;
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

function feed_autor(array $u, array $selos = []): array
{
    /* A foto que a pessoa escolheu ganha da que veio da Twitch. */
    $foto = !empty($u['foto_propria'])
        ? api_base() . '/feed.php?a=foto&login=' . rawurlencode((string) $u['login'])
        : (string) ($u['foto'] ?: '');

    return [
        'login' => (string) $u['login'],
        'nome'  => (string) ($u['nome_exibicao'] ?: $u['login']),
        'foto'  => $foto,
        'selos' => $selos[(int) ($u['usuario_id'] ?? $u['id'] ?? 0)] ?? [],
        /* Quem está no ar agora. A pergunta à Twitch é uma por minuto pro
           site inteiro, não uma por post — ver api/lib/aovivo.php. */
        'aovivo' => ao_vivo_esta((string) $u['login']),
    ];
}

/**
 * Preenche nome e foto de quem entrou ANTES destas colunas existirem.
 *
 * Sem isto, todo mundo que já tinha conta ficaria com a letra no círculo
 * até entrar de novo — e ninguém entra de novo sem motivo. Uma leitura da
 * Twitch resolve trinta de uma vez, e ela só acontece enquanto sobra
 * alguém sem foto: preenchido uma vez, nunca mais.
 */
function feed_completa(array $logins): void
{
    $logins = array_slice(array_values(array_unique($logins)), 0, 30);
    if (!$logins) return;

    try {
        require_once __DIR__ . '/lib/twitch.php';
        $q = implode('&', array_map(fn($l) => 'login=' . rawurlencode($l), $logins));
        [$http, $r] = tw_helix_app('GET', '/users?' . $q);
        if ($http !== 200 || empty($r['data'])) return;

        $up = db()->prepare('UPDATE usuarios SET nome_exibicao = ?, foto = ? WHERE login = ?');
        foreach ($r['data'] as $u) {
            if (empty($u['login'])) continue;
            $up->execute([
                $u['display_name'] ?? null,
                $u['profile_image_url'] ?? null,
                strtolower((string) $u['login']),
            ]);
        }
    } catch (Throwable $e) { /* a Twitch fora do ar não pode derrubar o feed */ }
}

/** Os comentários de um post, do mais antigo pro mais novo. */
function feed_comentarios(int $post): array
{
    $st = db()->prepare(
        'SELECT c.id, c.texto, c.usuario_id, ' . sprintf(FEED_HA, 'c') . ' AS ha,
                u.login, u.nome_exibicao, u.foto, u.foto_propria, u.selo_artista, u.selo_streamer
           FROM post_comentarios c JOIN usuarios u ON u.id = c.usuario_id
          WHERE c.post_id = ? AND c.escondido = 0
          ORDER BY c.id LIMIT 100'
    );
    $st->execute([$post]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    $selos = selos_de(array_column($linhas, 'usuario_id'));

    return array_map(fn($c) => [
        'id'    => (int) $c['id'],
        'texto' => (string) $c['texto'],
        'ha'    => (int) $c['ha'],
        'autor' => feed_autor($c, $selos),
    ], $linhas);
}

/* ---------- comentários de um post ---------- */
if (($_GET['a'] ?? '') === 'comentarios') {
    header('Cache-Control: private, no-store');
    json_saida(['comentarios' => feed_comentarios((int) ($_GET['id'] ?? 0))]);
}

/* ---------- ler o feed ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $eu = quem_talvez();
    $lista = [];
    try {
        $antes = (int) ($_GET['antes'] ?? 0);
        $sql = 'SELECT p.id, p.texto, p.arquivo, p.criado_em, p.usuario_id,
                       u.login, u.nome_exibicao, u.foto, u.foto_propria,
                       u.selo_artista, u.selo_streamer,
                       (SELECT COUNT(*) FROM post_curtidas k WHERE k.post_id = p.id) AS curtidas,
                       (SELECT COUNT(*) FROM post_comentarios c
                         WHERE c.post_id = p.id AND c.escondido = 0) AS comentarios,
                       (SELECT COUNT(*) FROM post_curtidas k2
                         WHERE k2.post_id = p.id AND k2.usuario_id = ?) AS curti,
                       ' . sprintf(FEED_HA, 'p') . ' AS ha
                  FROM posts p JOIN usuarios u ON u.id = p.usuario_id
                 WHERE p.escondido = 0';
        if ($antes > 0) $sql .= ' AND p.id < ' . $antes;
        $sql .= ' ORDER BY p.id DESC LIMIT ' . FEED_PAGINA;

        $st = db()->prepare($sql);
        $st->execute([$eu]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        $selos = selos_de(array_column($linhas, 'usuario_id'));

        /* Em consulta separada, e não como subconsulta: a tabela de quem
           segue quem chegou depois, e sem isso o feed inteiro sumiria em
           quem ainda não rodou o SQL 045. */
        $sigo = [];
        if ($eu) {
            try {
                $sg = db()->prepare('SELECT seguido_id FROM feed_seguidores WHERE seguidor_id = ?');
                $sg->execute([$eu]);
                $sigo = array_flip(array_map('intval', $sg->fetchAll(PDO::FETCH_COLUMN)));
            } catch (Throwable $e) { /* sem a tabela, ninguém segue ninguém */ }
        }
        if (!empty($_GET['seguindo']) && $eu) {
            $linhas = array_values(array_filter($linhas, fn($p) => isset($sigo[(int) $p['usuario_id']])));
        }

        foreach ($linhas as $p) {
            $lista[] = [
                'id'          => (int) $p['id'],
                'texto'       => (string) $p['texto'],
                'imagem'      => $p['arquivo'] ? (int) $p['id'] : null,
                'ha'          => (int) $p['ha'],
                'curtidas'    => (int) $p['curtidas'],
                'comentarios' => (int) $p['comentarios'],
                'curti'       => (int) $p['curti'] > 0,
                'sigo'        => isset($sigo[(int) $p['usuario_id']]),
                'autor'       => feed_autor($p, $selos),
            ];
        }
    } catch (Throwable $e) { /* tabela ainda não criada */ }

    /* Quem ainda está sem foto ganha uma agora, e a próxima leitura já a
       encontra pronta no banco. */
    $sem = [];
    foreach ($lista as $p) {
        if ($p['autor']['foto'] === '') $sem[] = $p['autor']['login'];
    }
    if ($sem) {
        feed_completa($sem);
        $st2 = db()->prepare('SELECT id AS usuario_id, login, nome_exibicao, foto, foto_propria
                                FROM usuarios WHERE login = ?');
        foreach ($lista as &$p) {
            if ($p['autor']['foto'] !== '') continue;
            $guarda = $p['autor']['selos'];
            $st2->execute([$p['autor']['login']]);
            $u = $st2->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $p['autor'] = feed_autor($u);
                $p['autor']['selos'] = $guarda;
            }
        }
        unset($p);
    }

    /* COM CHAVE, NADA DE CACHE COMPARTILHADO.

       A resposta passa a conter 'curti', que é de UMA pessoa. Guardada num
       cache público, ela apareceria pra outra — e o feed diria que alguém
       curtiu o que não curtiu. Sem chave a resposta é igual pra todo mundo
       e pode ser guardada, mas por pouco tempo: quem acabou de postar quer
       ver o post, não o feed de quinze segundos atrás. */
    header('Cache-Control: ' . ($eu ? 'private, no-store' : 'public, max-age=15'));
    json_saida(['posts' => $lista, 'texto_max' => FEED_TEXTO, 'comenta_max' => FEED_COMENTA]);
}

/* ---------- daqui pra baixo precisa de conta ---------- */
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

$ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$ad->execute([$uid]);
$souAdmin = (bool) $ad->fetchColumn();

/** O nome de quem está agindo, pro texto do aviso. */
function feed_meu_nome(int $uid): string
{
    static $nome = null;
    if ($nome !== null) return $nome;
    $st = db()->prepare('SELECT COALESCE(NULLIF(nome_exibicao, ""), login) FROM usuarios WHERE id = ?');
    $st->execute([$uid]);
    return $nome = (string) ($st->fetchColumn() ?: 'alguém');
}

/* ---------- apagar e esconder ---------- */
/* Pelo cabeçalho, e não por adivinhar dos campos: um post sem texto tem que
   receber "escreva alguma coisa", e não cair no caminho de apagar. */
if (strpos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== 0) {
    $d    = corpo_json();
    $acao = (string) ($d['acao'] ?? '');

    /* SEGUIR AQUI DENTRO, E NÃO NA TWITCH.

       A Twitch desligou em 2021 a API que deixava um site seguir um canal
       por você — era usada pra fabricar seguidor. Este seguir é do site:
       serve pro filtro do feed e pro aviso de quem ganhou o seguidor. */
    if ($acao === 'seguir' || $acao === 'deixar_de_seguir') {
        $login = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d['login'] ?? '')));
        if ($login === '') json_saida(['erro' => 'Falta dizer quem.'], 400);

        $st = db()->prepare('SELECT id FROM usuarios WHERE LOWER(login) = ? LIMIT 1');
        $st->execute([$login]);
        $alvo = (int) ($st->fetchColumn() ?: 0);
        if (!$alvo) json_saida(['erro' => 'Essa conta não existe aqui.'], 404);
        if ($alvo === $uid) json_saida(['erro' => 'Você não segue você mesmo.'], 400);

        try {
            if ($acao === 'seguir') {
                db()->prepare('INSERT IGNORE INTO feed_seguidores (seguidor_id, seguido_id) VALUES (?, ?)')
                    ->execute([$uid, $alvo]);
                notifica($alvo, 'seguidor', feed_meu_nome($uid) . ' começou a seguir você',
                         '#/inicio', $uid, 'seguidor:' . $uid);
            } else {
                db()->prepare('DELETE FROM feed_seguidores WHERE seguidor_id = ? AND seguido_id = ?')
                    ->execute([$uid, $alvo]);
            }
        } catch (Throwable $e) {
            json_saida(['erro' => 'Ainda não dá pra seguir neste servidor.'], 503);
        }

        json_saida(['ok' => true, 'sigo' => $acao === 'seguir']);
    }

    $id = (int) ($d['id'] ?? 0);
    if ($id <= 0) json_saida(['erro' => 'Falta dizer qual post.'], 400);

    if ($acao === 'curtir') {
        $st = db()->prepare('SELECT usuario_id FROM posts WHERE id = ? AND escondido = 0');
        $st->execute([$id]);
        $dono = (int) ($st->fetchColumn() ?: 0);
        if (!$dono) json_saida(['erro' => 'Esse post não existe mais.'], 404);

        /* Vai e volta no mesmo botão: apagar primeiro e conferir quantas
           linhas sumiram diz se era pra curtir ou descurtir, sem consulta
           extra e sem janela entre ler e escrever. */
        $del = db()->prepare('DELETE FROM post_curtidas WHERE post_id = ? AND usuario_id = ?');
        $del->execute([$id, $uid]);
        $curti = false;

        if ($del->rowCount() === 0) {
            db()->prepare('INSERT IGNORE INTO post_curtidas (post_id, usuario_id) VALUES (?, ?)')
                ->execute([$id, $uid]);
            $curti = true;
        }

        /* Só na curtida, nunca na descurtida — e o ref impede que
           descurtir e curtir de novo vire aviso novo. */
        if ($curti) {
            notifica($dono, 'curtida', feed_meu_nome($uid) . ' curtiu o seu zoc',
                     '#/inicio', $uid, 'curtida:' . $id . ':' . $uid);
        }

        $c = db()->prepare('SELECT COUNT(*) FROM post_curtidas WHERE post_id = ?');
        $c->execute([$id]);
        json_saida(['ok' => true, 'curti' => $curti, 'curtidas' => (int) $c->fetchColumn()]);
    }

    if ($acao === 'comentar') {
        trava('comentario', 40, 3600);

        $texto = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) ($d['texto'] ?? '')));
        $texto = mb_substr($texto, 0, FEED_COMENTA);
        if ($texto === '') json_saida(['erro' => 'Escreva alguma coisa.'], 400);

        $st = db()->prepare('SELECT usuario_id FROM posts WHERE id = ? AND escondido = 0');
        $st->execute([$id]);
        $dono = (int) ($st->fetchColumn() ?: 0);
        if (!$dono) json_saida(['erro' => 'Esse post não existe mais.'], 404);

        $ja = db()->prepare(
            'SELECT COUNT(*) FROM post_comentarios
              WHERE usuario_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 20 SECOND)'
        );
        $ja->execute([$uid]);
        if ((int) $ja->fetchColumn() > 0) json_saida(['erro' => 'Espere um pouco antes do próximo.'], 429);

        db()->prepare('INSERT INTO post_comentarios (post_id, usuario_id, texto) VALUES (?, ?, ?)')
            ->execute([$id, $uid, $texto]);

        $eu = feed_meu_nome($uid);
        notifica($dono, 'comentario', $eu . ' comentou no seu zoc', '#/inicio', $uid, null);
        notifica_marcados($texto, $uid, $eu, '#/inicio', 'men-c:' . db()->lastInsertId());

        json_saida(['ok' => true, 'comentarios' => feed_comentarios($id)]);
    }

    if ($acao === 'comentario_apagar') {
        $st = db()->prepare(
            'SELECT c.usuario_id, c.post_id, p.usuario_id AS dono
               FROM post_comentarios c JOIN posts p ON p.id = c.post_id
              WHERE c.id = ?'
        );
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) json_saida(['erro' => 'Esse comentário não existe mais.'], 404);

        /* Quem escreveu, o dono do post e o admin. O dono do post entra
           porque comentário ruim aparece embaixo do nome dele. */
        if ((int) $c['usuario_id'] !== $uid && (int) $c['dono'] !== $uid && !$souAdmin) {
            json_saida(['erro' => 'Esse comentário não é seu.'], 403);
        }

        db()->prepare('DELETE FROM post_comentarios WHERE id = ?')->execute([$id]);
        json_saida(['ok' => true, 'comentarios' => feed_comentarios((int) $c['post_id'])]);
    }

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

/* ---------- trocar a foto de perfil ---------- */
if (($_POST['acao'] ?? '') === 'foto') {
    trava('foto', 10, 3600);

    if (empty($_FILES['foto']) || (int) ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_saida(['erro' => 'Escolha uma imagem.'], 400);
    }

    $f = $_FILES['foto'];
    if ((int) $f['size'] > FEED_FOTO) json_saida(['erro' => 'A foto pode ter no máximo 1 MB.'], 400);
    if (!is_uploaded_file($f['tmp_name'])) json_saida(['erro' => 'Arquivo inválido.'], 400);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($f['tmp_name']);
    if (!isset(FEED_TIPOS[$mime])) json_saida(['erro' => 'Vale JPG, PNG, WEBP ou GIF.'], 400);

    if (!pasta_privada(FEED_DIR)) json_saida(['erro' => 'Não consegui guardar a foto aqui no servidor.'], 500);

    $novo = bin2hex(random_bytes(16)) . '.' . FEED_TIPOS[$mime];
    if (!move_uploaded_file($f['tmp_name'], feed_caminho($novo))) {
        json_saida(['erro' => 'Não consegui guardar a foto aqui no servidor.'], 500);
    }

    /* A antiga sai do disco: foto de perfil não é histórico. */
    $st = db()->prepare('SELECT foto_propria FROM usuarios WHERE id = ?');
    $st->execute([$uid]);
    $velha = (string) ($st->fetchColumn() ?: '');
    if ($velha !== '') @unlink(feed_caminho($velha));

    db()->prepare('UPDATE usuarios SET foto_propria = ? WHERE id = ?')->execute([$novo, $uid]);
    json_saida(['ok' => true]);
}

/* ---------- publicar ---------- */
trava('post', 30, 3600);

$texto = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) ($_POST['texto'] ?? '')));
$texto = mb_substr($texto, 0, FEED_TEXTO);
if ($texto === '') json_saida(['erro' => 'Escreva alguma coisa.'], 400);

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

notifica_marcados($texto, $uid, feed_meu_nome($uid), '#/inicio', 'men-p:' . db()->lastInsertId());

json_saida(['ok' => true]);
