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
$plat  = (string) ($_GET['plataforma'] ?? 'twitch');
if (!contagem_vale($plat, $fonte)) {
    json_saida(['erro' => 'Essa plataforma não responde esse número.'], 400);
}

/* Pergunta de verdade, sem esperar o minuto do cache: quem abriu esta tela
   quer saber se funciona AGORA, não se funcionava há um minuto. */
$valor = contagem($uid, $fonte, 0, $plat);
$e = contagem_estado($uid, $fonte, $plat);

/* O escopo que a Twitch exige pra contar seguidores. Se ele não está gravado,
   dá pra dizer isso antes mesmo de a chamada falhar. */
$st = db()->prepare('SELECT tw_escopos FROM usuarios WHERE id = ?');
$st->execute([$uid]);
$escopos = explode(' ', (string) $st->fetchColumn());

/* Escopo só existe pra Twitch: o Kick vem por OAuth próprio e o YouTube
   nem token de usuário usa. */
$precisa = $plat !== 'twitch' ? '' : (['seguidores' => 'moderator:read:followers',
            'subs'       => 'channel:read:subscriptions',
            'viewers'    => ''][$fonte] ?? '');
$faltaEscopo = $precisa !== '' && !in_array($precisa, $escopos, true);

/* A mensagem da exceção passa direto: ela já é uma frase em português e diz
   mais do que qualquer tradução minha por cima. */
$conhecidos = ['', 'nunca', 'permissao', 'proibido', 'espera', 'sem-resposta'];
$explica = '';

if (!in_array($e['erro'], $conhecidos, true) && strpos($e['erro'], 'erro-') !== 0) {
    $explica = $e['erro'];
} elseif ($faltaEscopo || $e['erro'] === 'permissao') {
    $explica = 'A sua conta da Twitch foi ligada antes desta permissão existir. '
             . 'Saia e entre de novo no site pra liberar — leva dez segundos e não desfaz nada.';
} elseif ($e['erro'] === 'proibido') {
    $explica = 'A Twitch recusou a leitura. Se este canal não é seu, só o dono consegue ver isso.';
} elseif ($e['erro'] === 'espera') {
    $explica = 'A Twitch pediu pra esperar um pouco. Costuma se resolver sozinho em minutos.';
} elseif ($e['erro'] === 'sem-resposta') {
    $explica = 'Não cheguei a falar com a Twitch. Pode ser rede do servidor, ou a conta não estar ligada.';
} elseif ($e['erro'] !== '' && $e['erro'] !== 'nunca') {
    $explica = 'A Twitch respondeu com um erro que eu não esperava (' . $e['erro'] . ').';
}

json_saida([
    'plataforma'   => $plat,
    /* O código cru vai junto. Não é bonito na tela, mas quando a explicação
       não bastar é ele que diz o que aconteceu de verdade. */
    'codigo'       => $e['erro'],
    'fonte'        => $fonte,
    'valor'        => $valor,
    'idade'        => $e['idade'],
    'ok'           => $valor !== null && $e['erro'] === '',
    'falta_escopo' => $faltaEscopo,
    'explica'      => $explica,
]);
