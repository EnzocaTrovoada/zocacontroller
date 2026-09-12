<?php
/**
 * Modelos de speedrun: o de um serve pro outro.
 *
 * GET ?q=          — procura por jogo; os mais usados primeiro
 * GET ?id=         — os trechos de um modelo
 * POST publicar    — manda os seus trechos pra galeria de modelos
 * POST aplicar     — traz um modelo pra sua overlay, com os ícones junto
 * POST apagar      — o autor, ou o admin
 * POST ocultar     — só admin
 *
 * O modelo guarda só nome e ícone de cada trecho. Recorde é de quem correu:
 * no modelo, viraria tempo de outra pessoa, e a primeira corrida de quem
 * aplicasse já começaria perdendo.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/imagens.php';

cors();

const MOD_TRECHOS = 60;

/** Deixa passar só o que o modelo guarda: nome e ícone. */
function modelo_limpa(array $trechos): array
{
    $saida = [];
    foreach (array_slice($trechos, 0, MOD_TRECHOS) as $tr) {
        if (!is_array($tr)) continue;
        $nome = mb_substr(trim((string) ($tr['n'] ?? '')), 0, 40);
        if ($nome === '') continue;
        $item = ['n' => $nome];
        if (!empty($tr['i'])) $item['i'] = (int) $tr['i'];
        $saida[] = $item;
    }
    return $saida;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);

    try {
        if ($id > 0) {
            $st = db()->prepare(
                'SELECT m.*, u.login FROM corridas_modelo m JOIN usuarios u ON u.id = m.usuario_id
                  WHERE m.id = ? AND m.oculto = 0'
            );
            $st->execute([$id]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m) json_saida(['erro' => 'Esse modelo não existe mais.'], 404);

            $trechos = json_decode((string) $m['trechos'], true) ?: [];
            json_saida(['modelo' => [
                'id'        => (int) $m['id'],
                'jogo'      => (string) $m['jogo'],
                'categoria' => (string) ($m['categoria'] ?? ''),
                'autor'     => (string) $m['login'],
                'usos'      => (int) $m['usos'],
                /* Só os nomes: o ícone de outra conta não abre com a chave
                   pública de quem está olhando. Ele vem na hora de aplicar. */
                'trechos'   => array_map(fn($t) => (string) ($t['n'] ?? ''), $trechos),
            ]]);
        }

        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
        if ($q !== '') {
            $st = db()->prepare(
                "SELECT m.id, m.jogo, m.categoria, m.usos, m.trechos, u.login
                   FROM corridas_modelo m JOIN usuarios u ON u.id = m.usuario_id
                  WHERE m.oculto = 0 AND m.jogo LIKE ?
                  ORDER BY m.usos DESC, m.id DESC LIMIT 30"
            );
            $st->execute(['%' . $q . '%']);
        } else {
            $st = db()->query(
                "SELECT m.id, m.jogo, m.categoria, m.usos, m.trechos, u.login
                   FROM corridas_modelo m JOIN usuarios u ON u.id = m.usuario_id
                  WHERE m.oculto = 0 ORDER BY m.usos DESC, m.id DESC LIMIT 30"
            );
        }

        $lista = array_map(fn($m) => [
            'id'        => (int) $m['id'],
            'jogo'      => (string) $m['jogo'],
            'categoria' => (string) ($m['categoria'] ?? ''),
            'autor'     => (string) $m['login'],
            'usos'      => (int) $m['usos'],
            'quantos'   => count(json_decode((string) $m['trechos'], true) ?: []),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $lista = [];   /* tabela ainda não criada */
    }

    header('Cache-Control: public, max-age=60');
    json_saida(['modelos' => $lista]);
}

$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

$d    = corpo_json();
$acao = (string) ($d['acao'] ?? '');
$id   = (int) ($d['id'] ?? 0);

if ($acao === 'publicar') {
    trava('modelo', 10, 3600);

    $jogo = mb_substr(trim((string) ($d['jogo'] ?? '')), 0, 60);
    if ($jogo === '') json_saida(['erro' => 'Escreva o nome do jogo.'], 400);

    $trechos = modelo_limpa(is_array($d['trechos'] ?? null) ? $d['trechos'] : []);
    if (!$trechos) json_saida(['erro' => 'Crie os trechos antes de publicar o modelo.'], 400);

    db()->prepare('INSERT INTO corridas_modelo (usuario_id, jogo, categoria, trechos) VALUES (?, ?, ?, ?)')
        ->execute([$uid, $jogo, mb_substr(trim((string) ($d['categoria'] ?? '')), 0, 60) ?: null,
                   json_encode($trechos, JSON_UNESCAPED_UNICODE)]);

    json_saida(['ok' => true, 'id' => (int) db()->lastInsertId()]);
}

if ($acao === 'aplicar') {
    $st = db()->prepare('SELECT * FROM corridas_modelo WHERE id = ? AND oculto = 0');
    $st->execute([$id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) json_saida(['erro' => 'Esse modelo não existe mais.'], 404);

    $trechos = json_decode((string) $m['trechos'], true) ?: [];

    /* Os ícones são copiados pra conta de quem aplica: eles são servidos
       pela chave pública da overlay do DONO do arquivo, então sem a cópia
       apareceriam quebrados pra todo mundo menos pro autor. */
    $mapa = img_copia((int) $m['usuario_id'], $uid, array_column($trechos, 'i'));

    $saida = [];
    foreach ($trechos as $t) {
        $item = ['n' => (string) ($t['n'] ?? '')];
        if (!empty($t['i']) && isset($mapa[(int) $t['i']])) $item['i'] = $mapa[(int) $t['i']];
        $saida[] = $item;
    }

    db()->prepare('UPDATE corridas_modelo SET usos = usos + 1 WHERE id = ?')->execute([$id]);

    json_saida(['ok' => true, 'jogo' => (string) $m['jogo'],
                'categoria' => (string) ($m['categoria'] ?? ''), 'trechos' => $saida]);
}

$ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$ad->execute([$uid]);
$souAdmin = (bool) $ad->fetchColumn();

if ($acao === 'apagar') {
    db()->prepare('DELETE FROM corridas_modelo WHERE id = ?' . ($souAdmin ? '' : ' AND usuario_id = ?'))
        ->execute($souAdmin ? [$id] : [$id, $uid]);
    json_saida(['ok' => true]);
}

if ($acao === 'ocultar') {
    if (!$souAdmin) json_saida(['erro' => 'Não encontrado.'], 404);
    db()->prepare('UPDATE corridas_modelo SET oculto = 1 WHERE id = ?')->execute([$id]);
    json_saida(['ok' => true]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
