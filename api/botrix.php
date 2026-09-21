<?php
/**
 * Lê os comandos públicos de um canal no Botrix.
 *
 * GET ?canal=fulano            → procura em todas as plataformas
 * GET ?canal=fulano&onde=kick  → só nessa
 *
 * POR QUE ISTO EXISTE, SE O DO STREAMELEMENTS NÃO PRECISOU.
 *
 * O StreamElements responde com CORS aberto, então aquela importação
 * acontece inteira no navegador e nada passa por aqui. O Botrix não manda
 * cabeçalho de CORS nenhum — o navegador recusa a leitura antes mesmo de
 * olhar a resposta. Sem este intermediário, não há importação.
 *
 * E COMO ISTO NÃO VIRA UM BURACO.
 *
 * Intermediário que busca endereço que o cliente escolhe é o jeito clássico
 * de alguém usar o nosso servidor pra bater onde ele não alcança — a rede
 * interna da hospedagem, por exemplo. Aqui o endereço é fixo no código: só
 * muda o nome do canal, e ele é conferido letra por letra antes. Não existe
 * parâmetro que mude o destino.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';

cors();
exige_painel();

/* A ordem importa: quem usa o Botrix e vem parar aqui quase sempre é da
   Twitch ou do Kick, e cada tentativa é uma ida à rede. */
const BOTRIX_ONDE  = ['twitch', 'kick', 'youtube', 'trovo'];
const BOTRIX_BYTES = 524288;     /* meio mega já é lista de comando demais */

/**
 * Uma consulta ao Botrix. Devolve a lista, ou null quando o canal não
 * existe naquela plataforma.
 */
function botrix_pede(string $canal, string $onde): ?array
{
    $url = 'https://botrix.live/api/public/commands?user=' . rawurlencode($canal)
         . '&platform=' . rawurlencode($onde);

    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   /* destino é fixo; desvio é suspeito */
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'ZocaHub/1.0 (+importar comandos)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        /* Corta o que passar do teto ainda durante o download, e não depois:
           depois já seria memória gasta. */
        CURLOPT_BUFFERSIZE     => 16384,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function ($r, $baixado) {
            return $baixado > BOTRIX_BYTES ? 1 : 0;
        },
    ]);

    $corpo = curl_exec($c);
    $http  = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);

    if ($corpo === false || $http !== 200) return null;

    $d = json_decode((string) $corpo, true);
    /* O Botrix devolve {"error":true} pra canal que não existe naquela
       plataforma — e devolve com 200, então o código HTTP não basta. */
    return is_array($d) && array_is_list($d) ? $d : null;
}

$canal = trim((string) ($_GET['canal'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $canal)) {
    json_saida(['erro' => 'Nome de canal inválido.'], 400);
}

$onde = (string) ($_GET['onde'] ?? '');
$tentar = in_array($onde, BOTRIX_ONDE, true) ? [$onde] : BOTRIX_ONDE;

/* Cada leitura pode virar quatro idas à rede: o teto é por isso. */
trava('botrix', 12, 120);

foreach ($tentar as $plat) {
    $lista = botrix_pede($canal, $plat);
    if ($lista === null) continue;

    json_saida([
        'canal'    => $canal,
        'onde'     => $plat,
        'comandos' => array_slice($lista, 0, 400),
    ]);
}

json_saida(['erro' => 'Não achei esse canal no Botrix. Confira o nome — é o mesmo que aparece no endereço botrix.live/u/SEUCANAL.'], 404);
