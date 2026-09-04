<?php
/**
 * Redefinir a chave do painel.
 *
 * A chave é guardada só em SHA-256: nem o servidor sabe qual é. Por isso aqui
 * não existe "mostrar a chave" — quem mostra é o navegador de quem já a tem.
 * O que existe é trocar por outra, para quando ela aparece num print, num VOD
 * ou na tela de alguém durante a live.
 *
 * Trocar QUEBRA tudo que carrega a chave antiga dentro do endereço — a ponte
 * no OBS, principalmente. Quem chama tem que avisar isso antes; aqui só
 * devolvo o que quebrou junto com a chave nova.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';

cors();

$quem = exige_painel();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_saida(['erro' => 'Só POST.'], 405);
}

$d = corpo_json();
if (($d['acao'] ?? '') !== 'redefinir') {
    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* Bem apertado de propósito: ninguém precisa trocar a chave toda hora, e
   cada troca derruba a ponte de quem estiver no ar. */
trava('chave_redefinir', 5, 3600);

$nova = chave_nova(24);
db()->prepare('UPDATE usuarios SET chave_painel = ? WHERE id = ?')
    ->execute([hash_chave($nova), $quem['usuario_id']]);

json_saida([
    'ok'    => true,
    'chave' => $nova,
    /* A chave só existe em claro AQUI, nesta resposta. Se a pessoa fechar a
       aba sem guardar, o jeito é entrar com a Twitch de novo — que também
       sorteia uma chave nova. */
    'quebrou' => [
        'A ponte no OBS para de funcionar até você trocar a URL dela.',
        'Links do painel que você tenha salvo nos favoritos param de valer.',
    ],
]);
