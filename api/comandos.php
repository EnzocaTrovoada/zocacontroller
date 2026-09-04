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
];
const QUEM_VALIDO = ['chat', 'mod', 'dono'];
const COMANDOS_MAX = 40;

$quem = quem_chama();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    /* A ponte também lê daqui: ela é 'painel' quando roda com a chave do dono. */
    $st = db()->prepare(
        'SELECT id, nome, acao, argumento, quem, espera FROM comandos WHERE usuario_id = ? ORDER BY nome'
    );
    $st->execute([$quem['usuario_id']]);
    json_saida(['comandos' => $st->fetchAll(), 'acoes' => ACOES_VALIDAS]);
}

if ($quem['tipo'] !== 'painel') {
    json_saida(['erro' => 'Só o dono do canal mexe nos comandos.'], 403);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');
trava('comandos', 60, 60);

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

$destino = (string) ($d['destino'] ?? '');
if (!in_array($destino, ACOES_VALIDAS, true)) {
    json_saida(['erro' => 'Essa ação não existe.'], 400);
}

$quemPode = (string) ($d['quem'] ?? 'mod');
if (!in_array($quemPode, QUEM_VALIDO, true)) $quemPode = 'mod';

/* Pânico e voltar mexem na live inteira: liberar pro chat seria entregar o
   botão de desligar pra qualquer um que entrasse. */
if (in_array($destino, ['panico', 'voltar', 'ganhou'], true) && $quemPode === 'chat') {
    json_saida(['erro' => 'Essa ação é forte demais pra liberar pro chat inteiro.'], 400);
}

$argumento = mb_substr(trim((string) ($d['argumento'] ?? '')), 0, 120);
$espera    = max(1, min(600, (int) ($d['espera'] ?? 5)));

$id = (int) ($d['id'] ?? 0);
if ($id > 0) {
    $st = db()->prepare(
        'UPDATE comandos SET nome = ?, acao = ?, argumento = ?, quem = ?, espera = ?
          WHERE id = ? AND usuario_id = ?'
    );
    $st->execute([$nome, $destino, $argumento ?: null, $quemPode, $espera, $id, $quem['usuario_id']]);
    json_saida(['ok' => true, 'id' => $id]);
}

$st = db()->prepare('SELECT COUNT(*) FROM comandos WHERE usuario_id = ?');
$st->execute([$quem['usuario_id']]);
if ((int) $st->fetchColumn() >= COMANDOS_MAX) {
    json_saida(['erro' => 'Você já tem ' . COMANDOS_MAX . ' comandos.'], 400);
}

try {
    db()->prepare(
        'INSERT INTO comandos (usuario_id, nome, acao, argumento, quem, espera) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$quem['usuario_id'], $nome, $destino, $argumento ?: null, $quemPode, $espera]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') json_saida(['erro' => 'Você já tem um comando com esse nome.'], 400);
    throw $e;
}

json_saida(['ok' => true, 'id' => (int) db()->lastInsertId()]);
