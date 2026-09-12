<?php
/**
 * A caixa de avisos de cada conta.
 *
 * GET                    — os últimos avisos e quantos não foram lidos
 * POST {acao:'lidas'}    — marca tudo (ou os ids mandados) como lido
 * POST {acao:'enviar'}   — só admin: recado pra um grupo ou pra uma pessoa
 *
 * A tela pergunta de minuto em minuto, e só com a aba aberta: a hospedagem
 * é compartilhada e não tem conexão viva pra empurrar nada.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/notificacoes.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/* Cada resposta é de UMA pessoa: cache compartilhado aqui entregaria a caixa
   de avisos de alguém pra outra. */
header('Cache-Control: private, no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $itens = [];
    $naoLidas = 0;

    try {
        $st = db()->prepare(
            'SELECT id, tipo, texto, rota, lida,
                    TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS ha
               FROM notificacoes WHERE usuario_id = ? ORDER BY id DESC LIMIT 40'
        );
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $itens[] = [
                'id'    => (int) $n['id'],
                'tipo'  => (string) $n['tipo'],
                'texto' => (string) $n['texto'],
                'rota'  => $n['rota'],
                'lida'  => (int) $n['lida'],
                'ha'    => (int) $n['ha'],
            ];
        }

        $c = db()->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0');
        $c->execute([$uid]);
        $naoLidas = (int) $c->fetchColumn();
    } catch (Throwable $e) { /* tabela nova: caixa vazia, e o site segue */ }

    json_saida(['nao_lidas' => $naoLidas, 'itens' => $itens]);
}

$d    = corpo_json();
$acao = (string) ($d['acao'] ?? '');

if ($acao === 'lidas') {
    $ids = array_slice(array_filter(array_map('intval', (array) ($d['ids'] ?? []))), 0, 100);

    try {
        if ($ids) {
            $vaz = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND id IN ($vaz)")
                ->execute(array_merge([$uid], $ids));
        } else {
            db()->prepare('UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0')
                ->execute([$uid]);
        }
    } catch (Throwable $e) { /* idem */ }

    json_saida(['ok' => true]);
}

/* ---------- daqui pra baixo, só admin ---------- */
$ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$ad->execute([$uid]);
if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

if ($acao === 'enviar') {
    $texto = trim((string) ($d['texto'] ?? ''));
    if ($texto === '') json_saida(['erro' => 'Escreva o recado.'], 400);

    $grupo = (string) ($d['grupo'] ?? 'todos');
    if ($grupo === 'login') {
        $login = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d['login'] ?? '')));
        if ($login === '') json_saida(['erro' => 'Falta o @ de quem vai receber.'], 400);
        $grupo = 'login:' . $login;
    }

    $n = notifica_grupo($grupo, $texto, (string) ($d['rota'] ?? '') ?: null);
    json_saida(['ok' => true, 'enviados' => $n]);
}

json_saida(['erro' => 'Ação desconhecida.'], 400);
