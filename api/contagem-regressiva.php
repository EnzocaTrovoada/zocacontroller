<?php
/**
 * A contagem regressiva: "começa em 12:43", e a cena que entra no fim.
 *
 * GET              → o alvo e a cena
 * POST {minutos}   → começa agora, acabando daqui a tantos minutos
 * POST {parar: 1}  → cancela
 *
 * QUEM TROCA A CENA É A PONTE, e não este arquivo: só ela fala com o OBS.
 * Aqui a gente guarda a hora, e ela pergunta.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/** Como está, com o padrão de quem nunca usou. */
function cr_le(int $uid): array
{
    $zero = ['alvo' => null, 'cena' => '', 'fim_texto' => 'Já vai começar!', 'trocada_em' => null];
    try {
        $st = db()->prepare('SELECT * FROM contagem_regressiva WHERE usuario_id = ?');
        $st->execute([$uid]);
        $l = $st->fetch();
        return $l ? array_merge($zero, $l) : $zero;
    } catch (Throwable $e) {
        return $zero;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $c = cr_le($uid);
    json_saida([
        'alvo'      => $c['alvo'],
        /* Quantos segundos faltam, contados AQUI. O relógio da máquina de
           quem assiste pode estar torto, e uma contagem que discorda do
           relógio do streamer é pior que não ter contagem. */
        'faltam'    => $c['alvo'] ? max(0, strtotime((string) $c['alvo']) - time()) : null,
        'cena'      => $c['cena'],
        'fim_texto' => $c['fim_texto'],
    ]);
}

$d = corpo_json();

if (!empty($d['parar'])) {
    try {
        db()->prepare('UPDATE contagem_regressiva SET alvo = NULL, trocada_em = NULL WHERE usuario_id = ?')
            ->execute([$uid]);
    } catch (Throwable $e) { /* nada a parar */ }
    json_saida(['ok' => true]);
}

/* SÓ "DAQUI A TANTOS MINUTOS", E O RELÓGIO É O DA PESSOA.

   A primeira versão aceitava uma hora do dia ("20:30") e resolvia ela
   aqui. Só que este servidor vive em UTC: alguém marcou 00:14 às 00:13 e
   recebeu uma contagem de vinte e uma horas, porque pro servidor eram
   03:13 e 00:14 já tinha passado — então ele jogou pra amanhã.

   Fuso é um jeito conhecido de errar. Quem sabe que horas são pra pessoa é
   o relógio DELA, então o painel faz a conta e manda os minutos. Aqui só
   entra "daqui a quanto", que não depende de fuso nenhum. */
$alvo = null;

if (isset($d['minutos'])) {
    /* Em segundos, e não em minutos inteiros: marcar "às 20:30" às 20:29:40
       são 20 segundos, e arredondar isso pra zero ou pra um minuto erraria
       justamente o caso que a pessoa está olhando. */
    $seg = max(5, min(86400, (int) round((float) $d['minutos'] * 60)));
    $alvo = date('Y-m-d H:i:s', time() + $seg);
}

/* JÁ TROQUEI, PODE ESQUECER.

   O painel troca a cena sozinho quando a contagem zera — ele está dentro
   do OBS e fala com ele direto. Avisar aqui impede que a ponte troque de
   novo alguns segundos depois, puxando a pessoa de volta pra cena de
   "já vai começar" se ela já tiver saído. */
if (!empty($d['trocada'])) {
    try {
        db()->prepare('UPDATE contagem_regressiva SET trocada_em = NOW()
                        WHERE usuario_id = ? AND trocada_em IS NULL')
            ->execute([$uid]);
    } catch (Throwable $e) { /* sem tabela: nada a marcar */ }
    json_saida(['ok' => true]);
}

/* SÓ A CENA, SEM MEXER NO PRAZO.

   Escolher a cena DEPOIS de marcar o tempo é o caso normal: a pessoa
   clica "15 min" e só então pensa em pra onde ir. Antes isso não salvava
   nada, e a contagem zerava sem trocar de cena — sem erro nenhum. */
if (!$alvo && array_key_exists('cena', $d)) {
    $cena = mb_substr(trim((string) $d['cena']), 0, 100);
    try {
        db()->prepare('INSERT INTO contagem_regressiva (usuario_id, cena) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE cena = VALUES(cena)')
            ->execute([$uid, $cena]);
    } catch (PDOException $e) {
        json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 066 no banco.')], 500);
    }
    json_saida(['ok' => true, 'cena' => $cena]);
}

if (!$alvo) json_saida(['erro' => 'Diga quantos minutos, ou a hora.'], 400);

$cena = mb_substr(trim((string) ($d['cena'] ?? '')), 0, 100);
$fim  = mb_substr(trim((string) ($d['fim_texto'] ?? '')), 0, 80) ?: 'Já vai começar!';

try {
    db()->prepare(
        'INSERT INTO contagem_regressiva (usuario_id, alvo, cena, fim_texto, trocada_em)
         VALUES (?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE alvo = VALUES(alvo), cena = VALUES(cena),
             fim_texto = VALUES(fim_texto), trocada_em = NULL'
    )->execute([$uid, $alvo, $cena, $fim]);
} catch (PDOException $e) {
    json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 066 no banco.')], 500);
}

json_saida(['ok' => true, 'alvo' => $alvo, 'faltam' => strtotime($alvo) - time()]);
