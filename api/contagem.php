<?php
/**
 * Como está a contagem automática da meta.
 *
 * Existe pra tela poder EXPLICAR em vez de ficar muda. Quando a Twitch recusa
 * — quase sempre porque a pessoa entrou no site antes de a permissão de
 * seguidores existir, e o token dela não tem o escopo — a meta ficava parada
 * no número antigo e ninguém descobria o motivo. Nem quem transmite, nem eu.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/contagem.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

$fonte = (string) ($_GET['fonte'] ?? 'seguidores');
if (!in_array($fonte, CONTAGEM_FONTES, true)) {
    json_saida(['erro' => 'Fonte desconhecida.'], 400);
}

/* Pergunta de verdade, sem esperar o minuto do cache: quem abriu esta tela
   quer saber se funciona AGORA, não se funcionava há um minuto. */
$valor = contagem($uid, $fonte, 0);
$e = contagem_estado($uid, $fonte);

/* O escopo que a Twitch exige pra contar seguidores. Se ele não está gravado,
   dá pra dizer isso antes mesmo de a chamada falhar. */
$st = db()->prepare('SELECT tw_escopos FROM usuarios WHERE id = ?');
$st->execute([$uid]);
$escopos = explode(' ', (string) $st->fetchColumn());

$precisa = ['seguidores' => 'moderator:read:followers',
            'subs'       => 'channel:read:subscriptions',
            'viewers'    => ''][$fonte] ?? '';
$faltaEscopo = $precisa !== '' && !in_array($precisa, $escopos, true);

$explica = '';
if ($faltaEscopo || $e['erro'] === 'permissao') {
    $explica = 'A sua conta da Twitch foi ligada antes desta permissão existir. '
             . 'Saia e entre de novo no site pra liberar — leva dez segundos e não desfaz nada.';
} elseif ($e['erro'] === 'proibido') {
    $explica = 'A Twitch recusou a leitura. Se este canal não é seu, só o dono consegue ver isso.';
} elseif ($e['erro'] === 'espera') {
    $explica = 'A Twitch pediu pra esperar um pouco. Costuma se resolver sozinho em minutos.';
} elseif ($e['erro'] !== '' && $e['erro'] !== 'nunca') {
    $explica = 'Não consegui falar com a Twitch agora. O número na tela é o último que deu certo.';
}

json_saida([
    'fonte'        => $fonte,
    'valor'        => $valor,
    'idade'        => $e['idade'],
    'ok'           => $valor !== null && $e['erro'] === '',
    'falta_escopo' => $faltaEscopo,
    'explica'      => $explica,
]);
