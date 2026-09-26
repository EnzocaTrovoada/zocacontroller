<?php
/**
 * Sorteio entre quem está no chat.
 *
 * O SORTEIO SAI DAQUI, E NUNCA DA PONTE.
 *
 * Dois motivos, e os dois bastam sozinhos. O primeiro é que sorteio vale
 * prêmio: número que o navegador escolhe é número que se escolhe. O segundo
 * é que a ponte não manda texto livre pro chat — ela é uma fonte de
 * navegador com endereço público, e quem abrisse leria o que ela diria.
 * Quem escreve no chat é o servidor, sempre.
 *
 * A ponte manda só a lista de quem falou. Ela não decide nada.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/chat.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/* Quem ganhou nas últimas horas não entra de novo. Doze horas cobre a live
   mais longa sem carregar o sorteio de ontem. */
const SORTEIO_ESPERA_H = 12;

/** Os últimos ganhadores, do mais novo pro mais velho. */
function sorteio_recentes(int $uid, int $quantos = 10): array
{
    try {
        $st = db()->prepare(
            'SELECT ganhador, quantos, quem, UNIX_TIMESTAMP(criado_em) AS quando
               FROM sorteios WHERE usuario_id = ?
              ORDER BY id DESC LIMIT ' . max(1, min(50, $quantos))
        );
        $st->execute([$uid]);
        return array_map(fn($l) => [
            'ganhador' => (string) $l['ganhador'],
            'quantos'  => (int) $l['quantos'],
            'quem'     => (string) $l['quem'],
            'quando'   => (int) $l['quando'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return [];
    }
}

/* ---------- o histórico, pro painel ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Cache-Control: private, no-store');
    json_saida(['sorteios' => sorteio_recentes($uid)]);
}

$d = corpo_json();

/* Um sorteio por vez. Sem isto, um mod apertando duas vezes tira dois
   ganhadores e um deles fica sem entender por que não valeu. */
trava('sorteio', 6, 60);

/* ---------- quem está concorrendo ----------

   A lista vem da ponte, que é quem lê o chat. Cada login é LIMPO aqui: o
   que chega de fora é texto, e login da Twitch só tem letra, número e
   sublinhado. Um nome que não caiba nisso não é um login — é alguém
   tentando outra coisa. */
$crus = is_array($d['chatters'] ?? null) ? $d['chatters'] : [];

$lista = [];
foreach ($crus as $c) {
    $login = strtolower(trim((string) $c));
    if (preg_match('/^[a-z0-9_]{2,25}$/', $login)) $lista[$login] = true;
    if (count($lista) >= 500) break;
}
$lista = array_keys($lista);

/* O DONO NÃO CONCORRE. Ele está lendo o chat pelo painel e sortear a si
   mesmo transformaria o recurso em piada na primeira vez que acontecesse. */
try {
    $eu = db()->prepare('SELECT login FROM usuarios WHERE id = ?');
    $eu->execute([$uid]);
    $meuLogin = strtolower((string) $eu->fetchColumn());
    if ($meuLogin !== '') $lista = array_values(array_diff($lista, [$meuLogin]));
} catch (Throwable $e) { /* sem o login, o dono concorre — não é grave */ }

if (!$lista) {
    json_saida(['erro' => 'Ninguém falou no chat ainda. O sorteio é entre quem está participando.'], 400);
}

/* ---------- quem já ganhou hoje sai ----------

   Sem isto o mesmo nome sai duas vezes numa live, e a terceira pessoa que
   vir isso acha que o sorteio é combinado. */
$jaGanhou = [];
try {
    $st = db()->prepare(
        'SELECT ganhador FROM sorteios
          WHERE usuario_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL ' . SORTEIO_ESPERA_H . ' HOUR)'
    );
    $st->execute([$uid]);
    $jaGanhou = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) { /* sem o SQL 076: ninguém ganhou ainda */ }

$podem = array_values(array_diff($lista, $jaGanhou));

/* TODO MUNDO JÁ GANHOU: volta a valer pra todos.

   Num chat de cinco pessoas, o quarto sorteio não teria ninguém — e recusar
   seria dizer "não dá" pra quem só queria sortear de novo. Recomeçar é o
   que a pessoa faria à mão. */
$recomecou = false;
if (!$podem) {
    $podem = $lista;
    $recomecou = true;
}

/* random_int, e não rand: é o sorteador do sistema, e a diferença importa
   justamente quando o resultado vale alguma coisa. */
$ganhador = $podem[random_int(0, count($podem) - 1)];

try {
    db()->prepare('INSERT INTO sorteios (usuario_id, ganhador, quantos, quem) VALUES (?, ?, ?, ?)')
        ->execute([$uid, $ganhador, count($podem), mb_substr((string) ($d['quem'] ?? ''), 0, 40)]);
} catch (Throwable $e) {
    json_saida(['erro' => 'Falta rodar o 076-sorteio.sql neste servidor.'], 503);
}

uso_marca($uid, 'sorteio');

/* ---------- o anúncio ----------

   O TEXTO É MONTADO AQUI, com o nome já validado acima. Ele nunca vem
   pronto de fora: mensagem que o servidor manda com texto de terceiro é
   como o bot do canal fala o que um estranho escreveu. */
$aviso = '@' . $ganhador . ' ganhou o sorteio! '
       . ($recomecou
           ? 'Todo mundo já tinha ganhado, então valeu pra todos de novo.'
           : 'Concorreram ' . count($podem) . '.');

$mandou = chat_enviar($uid, $aviso);

json_saida([
    'ok'        => true,
    'ganhador'  => $ganhador,
    'quantos'   => count($podem),
    'recomecou' => $recomecou,
    /* Se a mensagem não saiu, o sorteio VALE MESMO ASSIM — ele já está
       guardado. A tela conta o que houve em vez de fingir que deu certo. */
    'no_chat'   => !empty($mandou['ok']),
    'chat_erro' => empty($mandou['ok']) ? ($mandou['erro'] ?? '') : '',
]);
