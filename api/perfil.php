<?php
/**
 * Os overlays de quem está no painel: criar, salvar, apagar.
 *
 * A leitura pública (a que o OBS faz) NÃO passa por aqui — ela mora no
 * config-overlay.php, que só lê. Aqui é o lado que escreve, e ele exige a
 * chave do painel no cabeçalho.
 *
 * Quem cria o link é o servidor, sorteando. A pessoa não escolhe nome de link
 * nem guarda código de dono nenhum: o dono é a conta da Twitch com que ela já
 * entrou. Era esse vaivém de código que fazia o subathon precisar de dois
 * sites.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';

cors();

/* Espelha os tipos que o desenhista sabe montar. Quando as duas listas
   divergem, o overlay novo simplesmente nao pode ser criado — e o erro que
   aparece ("tipo de overlay desconhecido") nao diz onde esta o problema. */
const PERFIL_TIPOS = ['meta', 'relogio', 'contador', 'placar', 'subathon', 'chat', 'feed', 'musica'];
const PERFIL_CFG_MAX = 8192;

$quem = exige_painel();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = db()->prepare(
        'SELECT id, tipo, nome, chave_publica, config, atualizado_em
           FROM perfis WHERE usuario_id = ? ORDER BY id'
    );
    $st->execute([$quem['usuario_id']]);

    $perfis = array_map(function (array $p): array {
        $p['config'] = json_decode($p['config'], true) ?: [];
        return $p;
    }, $st->fetchAll());

    $acesso = acesso_do_usuario((int) $quem['usuario_id']);
    json_saida([
        'perfis' => $perfis,
        'maximo' => recursos_do_plano($acesso['ativo'] ? $acesso['plano'] : 'gratis')['perfis_max'],
    ]);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');
trava('perfil', 60, 60);

/** O estilo é JSON solto de propósito — quem valida chave por chave é o
    desenhista no navegador, que ignora o que não conhece. Aqui só barro o
    que não é objeto e o que é grande demais pra ser aparência. */
function perfil_config(array $d): string
{
    $cfg = $d['config'] ?? null;
    if (!is_array($cfg)) json_saida(['erro' => 'Configuração inválida.'], 400);
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > PERFIL_CFG_MAX) {
        json_saida(['erro' => 'Configuração grande demais.'], 400);
    }
    return $json;
}

function perfil_nome(array $d): string
{
    $n = trim((string) ($d['nome'] ?? ''));
    return mb_substr($n, 0, 64) ?: 'Sem nome';
}

if ($acao === 'criar') {
    $tipo = (string) ($d['tipo'] ?? '');
    if (!in_array($tipo, PERFIL_TIPOS, true)) {
        json_saida(['erro' => 'Tipo de overlay desconhecido: "' . $tipo . '". Este servidor aceita: '
            . implode(', ', PERFIL_TIPOS) . '. Se o tipo que você quer está faltando, o api/perfil.php '
            . 'do servidor está mais velho que a página.'], 400);
    }

    $acesso = acesso_do_usuario((int) $quem['usuario_id']);
    $maximo = recursos_do_plano($acesso['ativo'] ? $acesso['plano'] : 'gratis')['perfis_max'];

    $st = db()->prepare('SELECT COUNT(*) FROM perfis WHERE usuario_id = ?');
    $st->execute([$quem['usuario_id']]);
    if ((int) $st->fetchColumn() >= $maximo) {
        json_saida(['erro' => 'Você já tem ' . $maximo . ' overlays. Apague um pra criar outro.'], 400);
    }

    /* Sorteado aqui, e não pedido pra pessoa: nome escolhido à mão colide com
       o de outra gente, e ninguém quer batizar um link. */
    $chave = bin2hex(random_bytes(9));
    db()->prepare(
        'INSERT INTO perfis (usuario_id, tipo, nome, config, chave_publica) VALUES (?, ?, ?, ?, ?)'
    )->execute([$quem['usuario_id'], $tipo, perfil_nome($d), perfil_config($d), $chave]);

    json_saida(['ok' => true, 'id' => (int) db()->lastInsertId(), 'chave_publica' => $chave]);
}

/* Daqui pra baixo tudo mexe num overlay que já existe, e o WHERE carrega o
   usuario_id junto: sem isso, trocar o id na mão mexeria no overlay de outra
   pessoa. */
$id = (int) ($d['id'] ?? 0);
if ($id <= 0) json_saida(['erro' => 'Qual overlay?'], 400);

if ($acao === 'salvar') {
    $st = db()->prepare('UPDATE perfis SET nome = ?, config = ? WHERE id = ? AND usuario_id = ?');
    $st->execute([perfil_nome($d), perfil_config($d), $id, $quem['usuario_id']]);
    if (!$st->rowCount()) {
        /* rowCount zero também acontece quando nada mudou de verdade; conferir
           a existência separa "não é seu" de "salvou igual". */
        $ex = db()->prepare('SELECT 1 FROM perfis WHERE id = ? AND usuario_id = ?');
        $ex->execute([$id, $quem['usuario_id']]);
        if (!$ex->fetchColumn()) json_saida(['erro' => 'Esse overlay não é seu.'], 404);
    }
    json_saida(['ok' => true]);
}

if ($acao === 'apagar') {
    $st = db()->prepare('DELETE FROM perfis WHERE id = ? AND usuario_id = ?');
    $st->execute([$id, $quem['usuario_id']]);
    json_saida(['ok' => (bool) $st->rowCount()]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
