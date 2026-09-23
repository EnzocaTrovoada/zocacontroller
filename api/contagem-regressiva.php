<?php
/**
 * A contagem regressiva: "começa em 12:43", e a cena que entra no fim.
 *
 * GET              → o alvo e a cena
 * POST {minutos}   → começa agora, acabando daqui a tantos minutos
 * POST {hora}      → acaba numa hora do dia ("20:30")
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

/* Dois jeitos de dizer a mesma coisa, porque as duas cabeças existem:
   "daqui a 15 minutos" e "às 20:30". */
$alvo = null;

if (isset($d['minutos'])) {
    $m = max(1, min(600, (int) $d['minutos']));
    $alvo = date('Y-m-d H:i:s', time() + $m * 60);
}

if (!empty($d['hora']) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', (string) $d['hora'], $p)) {
    $hoje = strtotime(date('Y-m-d ') . $p[1] . ':' . $p[2] . ':00');
    /* Hora que já passou é amanhã: quem escreve 01:00 às 23h quer a
       madrugada, e não um prazo vencido há 22 horas. */
    if ($hoje <= time()) $hoje += 86400;
    $alvo = date('Y-m-d H:i:s', $hoje);
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
