<?php
/**
 * O backup do OBS.
 *
 * GET                  — as versões que a pessoa tem
 * GET ?a=baixar&t=     — o arquivo, por link assinado e de vida curta
 * POST acao=obs        — o retrato que a ponte tirou dentro do OBS
 * POST multipart arq   — o export que a pessoa fez no OBS e subiu na mão
 * POST acao=apagar     — tira uma versão
 *
 * DUAS PORTAS PORQUE SÃO DUAS COISAS DIFERENTES.
 *
 * O obs-websocket não sabe exportar coleção de cenas: não existe pedido
 * pra isso, só pra ler pedaço por pedaço (conferido no protocolo, v5). Ou
 * seja, a ponte consegue ler TUDO — cena, fonte, ajuste, filtro, posição —
 * mas o que ela monta é um retrato, não o arquivo que o OBS importa.
 *
 * Então o retrato automático serve pra saber exatamente o que você tinha e
 * remontar, e o export subido à mão serve pra voltar num clique. Fingir que
 * o retrato é um arquivo de importação seria pior que não ter backup: a
 * pessoa só descobriria que não presta no dia em que precisasse dele.
 *
 * A CHAVE DE TRANSMISSÃO NÃO ENTRA AQUI. Ela mora no perfil do OBS, não na
 * coleção de cenas, e a ponte não pergunta por ela (GetStreamServiceSettings
 * nunca é chamado). Nem por acidente ela chega neste servidor.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/backup.php';

/* ---------- baixar ----------

   Sem a chave do painel: quem baixa é o navegador indo num endereço, e
   endereço não carrega cabeçalho. O que prova o direito é a assinatura,
   que sai daqui valendo cinco minutos. */
if (($_GET['a'] ?? '') === 'baixar') {
    $carga = link_verificar((string) ($_GET['t'] ?? ''));
    $p = $carga === null ? [] : explode(':', $carga);
    if (count($p) !== 3 || $p[0] !== 'bk') { http_response_code(403); exit('Link vencido.'); }

    $st = db()->prepare('SELECT * FROM backups WHERE id = ? AND usuario_id = ?');
    $st->execute([(int) $p[2], (int) $p[1]]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) { http_response_code(404); exit('Não encontrado.'); }

    $guardado = @file_get_contents(bk_caminho((string) $b['arquivo']));
    $cru = $guardado === false ? null : bk_desembrulha($guardado);
    if ($cru === null) { http_response_code(500); exit('Não consegui abrir este backup.'); }

    $nome = 'obs-' . substr((string) $b['criado_em'], 0, 10) . '-' . (int) $b['id'] . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    header('Content-Length: ' . strlen($cru));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $cru;
    exit;
}

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_saida([
        'backups' => bk_lista($uid),
        'max'     => BK_MAX,
        /* A tela conta isto pra quem estiver sem: "o seu backup está sendo
           guardado sem cadeado" é informação de dono de site, não de quem
           transmite — mas quem transmite merece saber. */
        'cifrado' => cifra_pronta(),
    ]);
}

/* ---------- o arquivo que a pessoa exportou do OBS ---------- */
if (!empty($_FILES['arq'])) {
    trava('backup', 20, 3600);

    $f = $_FILES['arq'];
    if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_saida(['erro' => 'O arquivo não chegou inteiro. Tente de novo.'], 400);
    }
    if ((int) $f['size'] > BK_BYTES) {
        json_saida(['erro' => 'O arquivo passa de 8 MB. Isso não parece uma coleção de cenas.'], 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) json_saida(['erro' => 'Arquivo inválido.'], 400);

    $cru = (string) @file_get_contents($f['tmp_name']);
    $d = json_decode($cru, true);
    if (!is_array($d)) {
        json_saida(['erro' => 'Esse arquivo não é um JSON. No OBS: Coleção de Cenas, Exportar.'], 400);
    }

    /* Uma coleção de cenas exportada sempre tem 'sources'. Conferir isso
       evita a pessoa subir o arquivo errado e descobrir no pior dia. */
    if (!isset($d['sources']) || !is_array($d['sources'])) {
        json_saida(['erro' => 'Esse JSON não parece uma coleção de cenas do OBS — '
                            . 'faltam as fontes. No OBS: Coleção de Cenas, Exportar.'], 400);
    }

    $cenas = 0;
    foreach ($d['sources'] as $s) {
        if (is_array($s) && (($s['id'] ?? '') === 'scene')) $cenas++;
    }

    $r = bk_guarda($uid, $cru, 'arquivo', [
        'nome'   => (string) ($d['name'] ?? pathinfo((string) $f['name'], PATHINFO_FILENAME)),
        'cenas'  => $cenas,
        'fontes' => count($d['sources']),
    ]);
    if (empty($r['ok'])) json_saida(['erro' => $r['erro']], 500);

    json_saida(['ok' => true, 'repetido' => !empty($r['repetido']), 'backups' => bk_lista($uid)]);
}

$d    = corpo_json();
$acao = (string) ($d['acao'] ?? '');

/* ---------- o retrato que a ponte tirou ---------- */
if ($acao === 'obs') {
    trava('backup-obs', 30, 86400);

    $retrato = $d['retrato'] ?? null;
    if (!is_array($retrato) || empty($retrato['cenas'])) {
        json_saida(['erro' => 'Retrato vazio.'], 400);
    }

    $cru = json_encode($retrato, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($cru === false || strlen($cru) > BK_BYTES) {
        json_saida(['erro' => 'Retrato grande demais.'], 400);
    }

    $r = bk_guarda($uid, $cru, 'obs', [
        'nome'   => (string) ($retrato['colecao'] ?? ''),
        'cenas'  => count((array) $retrato['cenas']),
        'fontes' => count((array) ($retrato['fontes'] ?? [])),
    ]);
    if (empty($r['ok'])) json_saida(['erro' => $r['erro']], 500);

    json_saida(['ok' => true, 'repetido' => !empty($r['repetido'])]);
}

if ($acao === 'link') {
    $id = (int) ($d['id'] ?? 0);
    $st = db()->prepare('SELECT id FROM backups WHERE id = ? AND usuario_id = ?');
    $st->execute([$id, $uid]);
    if (!$st->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

    json_saida(['ok' => true, 'url' => api_base() . '/backup.php?a=baixar&t='
        . rawurlencode(link_assinar('bk:' . $uid . ':' . $id, 300))]);
}

if ($acao === 'apagar') {
    $st = db()->prepare('SELECT arquivo FROM backups WHERE id = ? AND usuario_id = ?');
    $st->execute([(int) ($d['id'] ?? 0), $uid]);
    $arq = $st->fetchColumn();
    if ($arq) {
        @unlink(bk_caminho((string) $arq));
        db()->prepare('DELETE FROM backups WHERE id = ? AND usuario_id = ?')
            ->execute([(int) $d['id'], $uid]);
    }
    json_saida(['ok' => true, 'backups' => bk_lista($uid)]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
