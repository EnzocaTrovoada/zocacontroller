<?php
/**
 * As conquistas de quem está no painel.
 *
 * GET               — confere (se já deu o tempo) e devolve tudo
 * GET ?a=conferir   — só confere; o painel chama isto ao abrir
 *
 * Conferir é o que entrega os prêmios. Ele acontece quando a pessoa abre o
 * painel ou a tela das conquistas: é quando ela está aqui pra ver, e é
 * quando o prêmio faz diferença.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/conquistas.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

header('Cache-Control: private, no-store');

if (($_GET['a'] ?? '') === 'conferir') {
    $novas = conq_confere($uid);
    /* O total vai junto pra medalha da barra: a tela compara com quantas a
       pessoa já tinha visto e mostra a diferença. */
    json_saida(['ok' => true, 'novas' => $novas, 'ganhas' => count(conq_ganhas($uid))]);
}

/* Na tela, a conferência pode ser mais frequente: quem abriu a tela quer ver
   o que acabou de fazer virar conquista. */
$novas = conq_confere($uid, 30);
$lista = conq_estado($uid);

json_saida([
    'conquistas' => $lista,
    'novas'      => $novas,
    'ganhas'     => count(array_filter($lista, fn($c) => $c['ganhou'])),
    'total'      => count($lista),
    'vagas'      => conq_bonus_overlays($uid),
]);
