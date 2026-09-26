<?php
/**
 * O botão físico: Stream Deck, teclado de macro, qualquer coisa que abra uma URL.
 *
 * O Stream Deck dispara um GET simples, sem cabeçalho nenhum. Então o segredo
 * viaja na URL — e URL fica no registro do servidor, no histórico do navegador
 * e num print da tela de configuração.
 *
 * Por isso NÃO é a chave do painel que vai aqui. Este token faz uma coisa só:
 * deixar um pedido na fila que a ponte já lê. Não lê nada, não configura nada,
 * não lista nada. Vazou, o estrago é alguém trocar a sua cena — e você revoga
 * num clique sem mexer em mais nada.
 *
 * GET  ?t=<token>&a=<ação>[&arg=<texto>]  — aperta o botão. Público de
 *                                           propósito: o segredo é o token.
 * GET  (com a chave do painel)             — a lista dos seus botões
 * POST (com a chave do painel)             — criar e revogar
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/fila.php';

cors();

/* ---------- apertar ----------

   ANTES DE QUALQUER EXIGÊNCIA DE CHAVE: quem aperta é um aparelho, e ele não
   tem a chave do painel nem deveria ter. */
if (isset($_GET['t'])) {
    /* Trava pelo IP antes de olhar o token: sem isto, o endereço vira um
       lugar pra adivinhar token de graça, um por requisição. */
    trava('botao', 60, 60);

    $token = trim((string) $_GET['t']);
    $acao  = strtolower(trim((string) ($_GET['a'] ?? '')));

    /* A resposta é texto puro e curta: o Stream Deck joga fora o corpo, e
       quem estiver testando no navegador precisa entender numa olhada. */
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $dono = 0;
    $podePanico = 0;
    try {
        $st = db()->prepare('SELECT id, usuario_id, pode_panico FROM botoes WHERE token_hash = ? LIMIT 1');
        $st->execute([hash_chave($token)]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if ($b) {
            $dono = (int) $b['usuario_id'];
            $podePanico = (int) $b['pode_panico'];
            db()->prepare('UPDATE botoes SET usos = usos + 1, usado_em = NOW() WHERE id = ?')
                ->execute([(int) $b['id']]);
        }
    } catch (Throwable $e) {
        http_response_code(503);
        exit("falta rodar o 077-botoes.sql neste servidor\n");
    }

    if (!$dono) {
        /* 404 e não 403: quem chutou não precisa saber que chegou perto. */
        http_response_code(404);
        exit("botao nao encontrado\n");
    }

    if (!isset(FILA_PERMITIDAS[$acao])) {
        http_response_code(400);
        exit("acao desconhecida: " . preg_replace('/[^a-z]/', '', $acao) . "\n");
    }

    /* SAIR DO PÂNICO É O ÚNICO QUE PEDE PERMISSÃO EXTRA.

       O pânico existe pra proteger de quem não deveria mandar, e um token em
       URL é justamente o que pode vazar num print. Quem quiser o botão de
       voltar, liga sabendo disso. */
    $sair = !empty($_GET['sair']);
    if ($acao === 'panico' && $sair && !$podePanico) {
        http_response_code(403);
        exit("este botao nao pode sair do panico\n");
    }

    $arg = trim((string) ($_GET['arg'] ?? ''));
    if ($acao === 'panico' && $sair) $arg = 'sair';

    fila_poe($dono, $acao, $arg, 'botão');
    uso_marca($dono, 'botao');

    exit("ok\n");
}

/* Daqui pra baixo é o dono configurando. */
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/** Quantos botões uma conta mantém. Passar disso é engano, não uso. */
const BOTOES_MAX = 30;

function botoes_lista(int $uid): array
{
    try {
        $st = db()->prepare(
            'SELECT id, nome, pode_panico, usos, UNIX_TIMESTAMP(usado_em) AS usado
               FROM botoes WHERE usuario_id = ? ORDER BY id'
        );
        $st->execute([$uid]);
        return array_map(fn($b) => [
            'id'     => (int) $b['id'],
            'nome'   => (string) $b['nome'],
            'panico' => (bool) $b['pode_panico'],
            'usos'   => (int) $b['usos'],
            'usado'  => $b['usado'] ? (int) $b['usado'] : null,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return [];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Cache-Control: private, no-store');
    json_saida([
        'botoes' => botoes_lista($uid),
        'max'    => BOTOES_MAX,
        /* A tela monta os endereços; ela precisa saber quais ações existem
           e o endereço de onde a API mora. */
        'acoes'  => array_keys(FILA_PERMITIDAS),
        'base'   => api_base() . '/botao.php',
    ]);
}

$d = corpo_json();
trava('botao-cfg', 20, 300);

if (($d['acao'] ?? '') === 'criar') {
    try {
        $c = db()->prepare('SELECT COUNT(*) FROM botoes WHERE usuario_id = ?');
        $c->execute([$uid]);
        if ((int) $c->fetchColumn() >= BOTOES_MAX) {
            json_saida(['erro' => 'Você já tem ' . BOTOES_MAX . ' tokens. Revogue um antes de criar outro.'], 400);
        }

        /* Sorteado aqui, e não pedido à pessoa: segredo que alguém escolhe é
           segredo curto. Trinta e dois hexa é o mesmo tamanho das outras. */
        $token = bin2hex(random_bytes(16));

        db()->prepare('INSERT INTO botoes (usuario_id, token_hash, nome, pode_panico) VALUES (?, ?, ?, ?)')
            ->execute([
                $uid,
                hash_chave($token),
                mb_substr(trim((string) ($d['nome'] ?? 'Stream Deck')), 0, 40) ?: 'Stream Deck',
                !empty($d['panico']) ? 1 : 0,
            ]);
    } catch (Throwable $e) {
        json_saida(['erro' => 'Falta rodar o 077-botoes.sql neste servidor.'], 503);
    }

    json_saida([
        'ok' => true,
        /* O TOKEN SAI UMA VEZ SÓ. O banco guarda o hash, então nem eu consigo
           mostrar de novo depois — o que também quer dizer que um vazamento
           do banco não entrega botão de ninguém. Perdeu, revoga e cria outro. */
        'token'  => $token,
        'botoes' => botoes_lista($uid),
    ]);
}

if (($d['acao'] ?? '') === 'revogar') {
    $id = (int) ($d['id'] ?? 0);
    if ($id <= 0) json_saida(['erro' => 'Qual botão?'], 400);

    /* O "AND usuario_id" é a tranca: sem ele, um id chutado revogaria o
       botão de outra conta. */
    db()->prepare('DELETE FROM botoes WHERE id = ? AND usuario_id = ?')->execute([$id, $uid]);
    json_saida(['ok' => true, 'botoes' => botoes_lista($uid)]);
}

json_saida(['erro' => 'Não conheço essa ação.'], 400);
