<?php
/**
 * Mede a subida da internet de quem transmite.
 *
 * POST <bytes quaisquer>  → { recebi: <quantos bytes chegaram> }
 *
 * POR QUE MEDIR AQUI E NÃO MANDAR PRO FAST.COM.
 *
 * Mandar pra fora é perder a pessoa no meio do caminho: ela sai, mede,
 * esquece qual dos números era o de subida, e volta com o de descida. O
 * número que interessa é um só e ele cabe num botão.
 *
 * ESTA MEDIDA NÃO É UM TESTE DE VELOCIDADE, E NÃO PRECISA SER.
 *
 * Ela mede até o nosso servidor, não até o servidor da Twitch, e o teto
 * dela é o que esta hospedagem compartilhada aceita — não o que a internet
 * da pessoa dá. Quem tem 900 Mbps vai ver bem menos que isso aqui.
 *
 * E tudo bem, porque a pergunta não é "quantos megas você tem". A pergunta
 * é "a sua subida passa dos 8,6 Mbps que o teto da Twitch pede?". Pra
 * responder isso, medir até uns 15 basta. Acima disso a resposta é a mesma:
 * a internet não é o seu limite.
 *
 * O corpo é lido e jogado fora em pedaços, sem nunca virar uma string
 * inteira na memória: são megabytes, e este servidor é dividido com outros.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';

cors();
exige_painel();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_saida(['erro' => 'Só POST.'], 405);
}

/* Cada medida são algumas idas seguidas; o teto cobre repetir sem virar
   jeito de gastar a banda da hospedagem. */
trava('velocidade', 30, 600);

$f = fopen('php://input', 'rb');
$recebi = 0;
if ($f) {
    while (!feof($f)) {
        $pedaco = fread($f, 65536);
        if ($pedaco === false) break;
        $recebi += strlen($pedaco);
        /* Teto duro: se algo mandar demais, a leitura para aqui em vez de
           seguir consumindo tempo de processador que é de todo mundo. */
        if ($recebi > 12 * 1024 * 1024) break;
    }
    fclose($f);
}

/* Quantos bytes CHEGARAM, e não quantos foram mandados. Se a hospedagem
   cortar o corpo no meio por causa do limite dela, quem está medindo
   precisa saber disso pra conta não sair errada pra mais. */
json_saida(['recebi' => $recebi]);
