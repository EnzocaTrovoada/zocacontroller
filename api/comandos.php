<?php
/**
 * Comandos que a pessoa inventa.
 *
 * Um comando novo NÃO é código novo: é um apelido pra uma ação que a ponte já
 * sabe fazer, com um argumento já preenchido e uma regra própria de quem pode
 * e de quanto tempo esperar. "!brb" vira "cena Cenário BRB".
 *
 * É de propósito que não dê pra inventar a AÇÃO: se o chat pudesse definir o
 * que a ponte executa, o chat controlaria o OBS sem limite. As ações são as
 * quinze que existem no código, e ponto.
 *
 * A ponte lê esta lista com a chave pública dela; o painel é quem escreve.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();

/* Espelha o registro da ponte. Se as duas listas divergirem, um comando
   apontaria pro nada — então o servidor recusa o que a ponte não conhece. */
const ACOES_VALIDAS = [
    'panico', 'voltar', 'mute', 'cena', 'replay', 'camera', 'som', 'fonte',
    'aposta', 'fechar', 'cancelar', 'ganhou', 'marcar', 'titulo', 'categoria',
    'pular', 'like', 'adicionar', 'fila', 'musica', 'vod',
];
const QUEM_VALIDO = ['chat', 'sub', 'vip', 'mod', 'supermod', 'dono'];
const COMANDOS_MAX = 40;

$quem = quem_chama();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    /* A ponte também lê daqui: ela é 'painel' quando roda com a chave do dono. */
    $st = db()->prepare(
        'SELECT id, nome, acao, argumento, quem, espera, passos FROM comandos WHERE usuario_id = ? ORDER BY nome'
    );
    $st->execute([$quem['usuario_id']]);

    /* A ponte le a lista de supermods daqui junto com os comandos: e uma
       requisicao so, e ela ja faz essa de qualquer jeito. */
    $sm = db()->prepare('SELECT supermods FROM usuarios WHERE id = ?');
    $sm->execute([$quem['usuario_id']]);

    /* Quem tem passos usa passos; quem nao tem vira uma lista de um passo.
       Assim quem le — a ponte e a tela — enxerga UM formato so, em vez de
       cada um ter que lembrar do jeito antigo. */
    $lista = array_map(function (array $c): array {
        $p = $c['passos'] ? json_decode($c['passos'], true) : null;
        $c['passos'] = is_array($p) && $p
            ? $p
            : [['acao' => $c['acao'], 'argumento' => $c['argumento']]];
        return $c;
    }, $st->fetchAll());

    json_saida([
        'comandos'  => $lista,
        'acoes'     => ACOES_VALIDAS,
        'supermods' => (string) ($sm->fetchColumn() ?: ''),
    ]);
}

if ($quem['tipo'] !== 'painel') {
    json_saida(['erro' => 'Só o dono do canal mexe nos comandos.'], 403);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');
trava('comandos', 60, 60);

if ($acao === 'supermods') {
    /* Logins da Twitch, separados por virgula ou espaco. Guardo em minusculas
       porque e assim que a etiqueta do chat chega. */
    $lista = preg_split('/[,\s]+/', mb_strtolower(trim((string) ($d['lista'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
    $lista = array_slice(array_unique(array_filter($lista, function ($n) {
        return preg_match('/^[a-z0-9_]{2,25}$/', $n);
    })), 0, 40);

    db()->prepare('UPDATE usuarios SET supermods = ? WHERE id = ?')
        ->execute([implode(',', $lista) ?: null, $quem['usuario_id']]);

    json_saida(['ok' => true, 'supermods' => implode(', ', $lista)]);
}

if ($acao === 'apagar') {
    $id = (int) ($d['id'] ?? 0);
    $st = db()->prepare('DELETE FROM comandos WHERE id = ? AND usuario_id = ?');
    $st->execute([$id, $quem['usuario_id']]);
    json_saida(['ok' => (bool) $st->rowCount()]);
}

if ($acao !== 'salvar') {
    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* O nome vai virar "!alguma-coisa" no chat: só o que dá pra digitar sem
   surpresa, e sem o prefixo — quem põe o "!" é a ponte. */
$nome = strtolower(trim((string) ($d['nome'] ?? '')));
$nome = preg_replace('/[^a-z0-9_-]/', '', $nome);
if ($nome === '' || strlen($nome) > 20) {
    json_saida(['erro' => 'Escolha um nome só com letras, números, - e _ (até 20).'], 400);
}
if (in_array($nome, ACOES_VALIDAS, true)) {
    json_saida(['erro' => 'Já existe um comando com esse nome. Escolha outro.'], 400);
}

/* Os passos, em ordem. Continua valendo a regra que importa: a AÇÃO não é
   livre. Se o chat pudesse definir o que a ponte executa, ele controlaria o
   OBS sem limite — então cada passo tem que ser uma das quinze do código. */
$passos = [];
foreach ((array) ($d['passos'] ?? []) as $passo) {
    $a = (string) ($passo['acao'] ?? '');
    if (!in_array($a, ACOES_VALIDAS, true)) continue;
    $passos[] = [
        'acao'      => $a,
        'argumento' => mb_substr(trim((string) ($passo['argumento'] ?? '')), 0, 500) ?: null,
    ];
    if (count($passos) >= 8) break;   /* uma corrente longa demais vira bagunça */
}

/* Sem passos explícitos, aceito o formato antigo de um só. */
if (!$passos && ($d['destino'] ?? '') !== '') {
    $passos[] = [
        'acao'      => (string) $d['destino'],
        'argumento' => mb_substr(trim((string) ($d['argumento'] ?? '')), 0, 500) ?: null,
    ];
}
if (!$passos) {
    json_saida(['erro' => 'Escolha pelo menos uma ação pro comando fazer.'], 400);
}
if (!in_array($passos[0]['acao'], ACOES_VALIDAS, true)) {
    json_saida(['erro' => 'Essa ação não existe.'], 400);
}
$destino = $passos[0]['acao'];

$quemPode = (string) ($d['quem'] ?? 'mod');
if (!in_array($quemPode, QUEM_VALIDO, true)) $quemPode = 'mod';

/* Pânico e voltar mexem na live inteira: liberar pro chat seria entregar o
   botão de desligar pra qualquer um que entrasse. */
/* Vale pra QUALQUER passo, não só pro primeiro: senão bastaria pôr o pânico
   em segundo lugar pra escapar da regra. */
foreach ($passos as $passo) {
    if (in_array($passo['acao'], ['panico', 'voltar', 'ganhou'], true)
        && in_array($quemPode, ['chat', 'sub', 'vip'], true)) {
        json_saida(['erro' => 'O "' . $passo['acao'] . '" é forte demais pra liberar fora da moderação.'], 400);
    }
}

$argumento = $passos[0]['argumento'];
$jsonPassos = json_encode($passos, JSON_UNESCAPED_UNICODE);
$espera    = max(1, min(600, (int) ($d['espera'] ?? 5)));

$id = (int) ($d['id'] ?? 0);
if ($id > 0) {
    $st = db()->prepare(
        'UPDATE comandos SET nome = ?, acao = ?, argumento = ?, quem = ?, espera = ?, passos = ?
          WHERE id = ? AND usuario_id = ?'
    );
    $st->execute([$nome, $destino, $argumento, $quemPode, $espera, $jsonPassos, $id, $quem['usuario_id']]);
    json_saida(['ok' => true, 'id' => $id]);
}

$st = db()->prepare('SELECT COUNT(*) FROM comandos WHERE usuario_id = ?');
$st->execute([$quem['usuario_id']]);
if ((int) $st->fetchColumn() >= COMANDOS_MAX) {
    json_saida(['erro' => 'Você já tem ' . COMANDOS_MAX . ' comandos.'], 400);
}

try {
    db()->prepare(
        'INSERT INTO comandos (usuario_id, nome, acao, argumento, quem, espera, passos)
              VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$quem['usuario_id'], $nome, $destino, $argumento, $quemPode, $espera, $jsonPassos]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') json_saida(['erro' => 'Você já tem um comando com esse nome.'], 400);
    throw $e;
}

json_saida(['ok' => true, 'id' => (int) db()->lastInsertId()]);
