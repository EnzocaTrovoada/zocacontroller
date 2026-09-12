<?php
/**
 * Convites de moderador.
 *
 * Um link por pessoa, de propósito: link compartilhado não dá para tirar de um
 * só quando alguém sai da equipe.
 *
 * GET                        → lista os convites; o link não vem, só pode_mostrar
 * POST {nome, pode:{...}}    → cria e devolve o link
 * POST {mostrar: id}         → o link de novo, se ele ficou guardado cifrado
 * POST {regenerar: id}       → derruba o link e cria outro, mesmo nome e poderes
 * POST {revogar: id}         → derruba na hora
 *
 * Quem autentica o mod continua sendo só o token_hash. O token_cifrado existe
 * pra mostrar, e só é lido ou gravado com a chave 'cifra' no config: sem ela,
 * este arquivo nem encosta na coluna, e subir ele antes do SQL 044 não quebra.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/cifra.php';

const LINK_MOD = 'https://mods.zocahop.com/mods.html#';

cors();
header('Cache-Control: no-store');   // resposta com link não pode ficar em cache
$quem = exige_painel();

/** Grava um convite e devolve [id, link, se o link ficou guardado pra mostrar]. */
function convite_novo(int $dono, string $nome, array $pode): array
{
    $token = chave_nova(24);

    db()->prepare(
        'INSERT INTO convites_mod (usuario_id, nome, token_hash, pode_cena, pode_audio, pode_canal)
              VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $dono, $nome, hash_chave($token),
        !empty($pode['cena'])  ? 1 : 0,
        !empty($pode['audio']) ? 1 : 0,
        !empty($pode['canal']) ? 1 : 0,
    ]);
    $id = (int) db()->lastInsertId();

    /* A coluna pode não existir ainda: guardar o cifrado é o que falha,
       nunca criar o convite. */
    $guardado = false;
    if (cifra_pronta()) {
        try {
            db()->prepare('UPDATE convites_mod SET token_cifrado = ? WHERE id = ?')
                ->execute([cifra($token), $id]);
            $guardado = true;
        } catch (Throwable $e) { /* sem a coluna, resta gerar de novo */ }
    }

    return [$id, LINK_MOD . $token, $guardado];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    /*
     * A idade vem calculada DAQUI, em segundos. DATETIME do MySQL nao carrega
     * fuso: o navegador leria como hora local e o servidor esta noutro fuso,
     * o que fazia "usou ontem" virar "usou hoje". Numero relativo nao tem fuso.
     */
    /* SELECT *: a coluna do cifrado chegou depois, e assim a lista não
       quebra em quem pôs a chave no config antes de rodar o SQL 044. */
    $st = db()->prepare(
        'SELECT *,
                TIMESTAMPDIFF(SECOND, criado_em, NOW())  AS criado_ha,
                TIMESTAMPDIFF(SECOND, ultimo_uso, NOW()) AS usado_ha
           FROM convites_mod WHERE usuario_id = ? ORDER BY id DESC'
    );
    $st->execute([$quem['usuario_id']]);

    json_saida(['convites' => array_map(fn($c) => [
        'id'           => (int) $c['id'],
        'nome'         => $c['nome'],
        'pode'         => [
            'cena'  => (bool) $c['pode_cena'],
            'audio' => (bool) $c['pode_audio'],
            'canal' => (bool) $c['pode_canal'],
        ],
        'criado_ha'    => (int) $c['criado_ha'],
        'usado_ha'     => $c['usado_ha'] === null ? null : (int) $c['usado_ha'],
        'revogado'     => (bool) $c['revogado'],
        'pode_mostrar' => !$c['revogado'] && cifra_pronta() && !empty($c['token_cifrado']),
    ], $st->fetchAll())]);
}

$d = corpo_json();

// ---------- revogar ----------
if (isset($d['revogar'])) {
    db()->prepare('UPDATE convites_mod SET revogado = 1 WHERE id = ? AND usuario_id = ?')
        ->execute([(int) $d['revogar'], $quem['usuario_id']]);
    json_saida(['ok' => true]);
}

// ---------- mostrar ----------
if (isset($d['mostrar'])) {
    trava('convite_mostrar', 20, 60);
    if (!cifra_pronta()) {
        json_saida(['erro' => 'Os links ainda não ficam guardados neste servidor. Use "gerar de novo".'], 503);
    }

    $st = db()->prepare(
        'SELECT id, nome, token_hash, token_cifrado FROM convites_mod
          WHERE id = ? AND usuario_id = ? AND revogado = 0'
    );
    $st->execute([(int) $d['mostrar'], $quem['usuario_id']]);
    $c = $st->fetch();
    if (!$c) {
        json_saida(['erro' => 'Esse convite não existe ou foi revogado.'], 404);
    }

    /* Bater com o hash garante que o link mostrado é o que entra, e que um
       cifrado copiado de outra linha não abre aqui. */
    $token = $c['token_cifrado'] === null ? null : decifra($c['token_cifrado']);
    if ($token === null || !hash_equals($c['token_hash'], hash_chave($token))) {
        json_saida(['erro' => 'O link de ' . $c['nome'] . ' não ficou guardado. Use "gerar de novo".'], 409);
    }

    json_saida(['ok' => true, 'id' => (int) $c['id'], 'nome' => $c['nome'], 'link' => LINK_MOD . $token]);
}

// ---------- regenerar ----------
if (isset($d['regenerar'])) {
    trava('convite_regenerar', 10, 60);

    $pdo = db();
    $pdo->beginTransaction();

    // FOR UPDATE: dois cliques seguidos deixariam dois links novos valendo.
    $st = $pdo->prepare(
        'SELECT id, nome, pode_cena, pode_audio, pode_canal FROM convites_mod
          WHERE id = ? AND usuario_id = ? AND revogado = 0 FOR UPDATE'
    );
    $st->execute([(int) $d['regenerar'], $quem['usuario_id']]);
    $velho = $st->fetch();
    if (!$velho) {
        $pdo->rollBack();
        json_saida(['erro' => 'Esse convite não existe ou foi revogado.'], 404);
    }

    $pdo->prepare('UPDATE convites_mod SET revogado = 1 WHERE id = ? AND usuario_id = ?')
        ->execute([$velho['id'], $quem['usuario_id']]);
    [$id, $link, $guardado] = convite_novo($quem['usuario_id'], $velho['nome'], [
        'cena'  => $velho['pode_cena'],
        'audio' => $velho['pode_audio'],
        'canal' => $velho['pode_canal'],
    ]);
    $pdo->commit();

    json_saida([
        'ok'           => true,
        'id'           => $id,
        'antigo'       => (int) $velho['id'],
        'nome'         => $velho['nome'],
        'link'         => $link,
        'pode_mostrar' => $guardado,
        'aviso'        => 'O link antigo de ' . $velho['nome'] . ' parou de funcionar. Mande este no lugar.'
                        . ($guardado ? '' : ' Copie agora: ele não aparece de novo.'),
    ]);
}

// ---------- criar ----------
$nome = trim((string) ($d['nome'] ?? ''));
if ($nome === '' || mb_strlen($nome) > 64) {
    json_saida(['erro' => 'Escreva o nome do moderador.'], 400);
}

$st = db()->prepare('SELECT COUNT(*) FROM convites_mod WHERE usuario_id = ? AND revogado = 0');
$st->execute([$quem['usuario_id']]);
if ((int) $st->fetchColumn() >= 30) {
    json_saida(['erro' => 'Você já tem 30 convites ativos. Revogue algum antes.'], 400);
}

[$id, $link, $guardado] = convite_novo($quem['usuario_id'], $nome, (array) ($d['pode'] ?? []));

json_saida([
    'ok'           => true,
    'id'           => $id,
    'nome'         => $nome,
    'link'         => $link,
    'pode_mostrar' => $guardado,
    'aviso'        => $guardado
        ? 'Mande para ' . $nome . '. Se perder, o link continua aqui na lista.'
        : 'Copie agora e mande para ' . $nome . '. Este link não aparece de novo.',
]);
