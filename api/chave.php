<?php
/**
 * A chave do painel e os aparelhos que entraram na conta.
 *
 * As chaves são guardadas só em SHA-256: nem o servidor sabe quais são. Por
 * isso não existe "mostrar a chave" — quem mostra é o navegador que já a tem.
 *
 *   GET                     a lista de aparelhos
 *   POST desconectar {id}   apaga a chave de um aparelho
 *   POST redefinir          troca a principal e desconecta todos os aparelhos
 *
 * Desconectar e redefinir QUEBRAM tudo que carrega aquela chave dentro do
 * endereço — a ponte no OBS, principalmente. Quem chama tem que avisar antes.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';

cors();

$quem  = exige_painel();
$uid   = (int) $quem['usuario_id'];
$minha = (int) ($quem['chave_id'] ?? 0);

/* ---------- a lista ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $lista = [[
        'id'       => 0,
        'aparelho' => 'Chave principal',
        'atual'    => $minha === 0,
    ]];
    try {
        $st = db()->prepare(
            'SELECT id, aparelho, DATE_FORMAT(criado_em, \'%d/%m/%Y\') AS desde,
                    TIMESTAMPDIFF(SECOND, COALESCE(visto_em, criado_em), NOW()) AS ha
               FROM chaves_painel WHERE usuario_id = ?
              ORDER BY COALESCE(visto_em, criado_em) DESC, id DESC'
        );
        $st->execute([$uid]);
        foreach ($st->fetchAll() as $l) {
            $lista[] = [
                'id'       => (int) $l['id'],
                'aparelho' => (string) $l['aparelho'] !== '' ? (string) $l['aparelho'] : 'Aparelho',
                'atual'    => (int) $l['id'] === $minha,
                'desde'    => (string) $l['desde'],
                'ha'       => (int) $l['ha'],
            ];
        }
    } catch (Throwable $e) { /* sem o SQL 052, só existe a principal */ }

    header('Cache-Control: private, no-store');
    json_saida(['aparelhos' => $lista]);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');

/* ---------- desconectar um aparelho ---------- */
if ($acao === 'desconectar') {
    trava('chave_desconectar', 30, 3600);
    $id = (int) ($d['id'] ?? 0);
    if ($id <= 0) {
        json_saida(['erro' => 'A chave principal não se desconecta sozinha: pra ela, use Redefinir.'], 400);
    }

    $apagou = false;
    try {
        $st = db()->prepare('DELETE FROM chaves_painel WHERE id = ? AND usuario_id = ?');
        $st->execute([$id, $uid]);
        $apagou = $st->rowCount() > 0;
    } catch (Throwable $e) { /* sem a tabela, não há o que apagar */ }

    json_saida(['ok' => $apagou, 'era_este' => $apagou && $id === $minha]);
}

if ($acao !== 'redefinir') {
    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* ---------- redefinir ---------- */

/* Bem apertado de propósito: ninguém precisa trocar a chave toda hora, e
   cada troca derruba a ponte de quem estiver no ar. */
trava('chave_redefinir', 5, 3600);

$nova = chave_nova(24);
db()->prepare('UPDATE usuarios SET chave_painel = ? WHERE id = ?')
    ->execute([hash_chave($nova), $uid]);

/* Redefinir é pra quando a chave vazou, e não dá pra saber qual: todas as
   dos aparelhos saem junto. */
try {
    db()->prepare('DELETE FROM chaves_painel WHERE usuario_id = ?')->execute([$uid]);
} catch (Throwable $e) { /* sem o SQL 052, só existia a principal */ }

json_saida([
    'ok'    => true,
    'chave' => $nova,
    /* A chave só existe em claro AQUI, nesta resposta. Se a pessoa fechar a
       aba sem guardar, é só entrar com a Twitch de novo. */
    'quebrou' => [
        'A ponte no OBS para de funcionar até você trocar a URL dela.',
        'Links do painel que você tenha salvo nos favoritos param de valer.',
        'Os outros aparelhos precisam entrar com a Twitch de novo.',
    ],
]);
