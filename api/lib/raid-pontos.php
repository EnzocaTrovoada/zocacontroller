<?php
/**
 * Os pontos de raid, e os dias de Pro que eles viram.
 *
 * TUDO AQUI EXISTE PORQUE O PRÊMIO É DINHEIRO.
 *
 * Quem ganha Pro de graça raidando tem motivo real pra fraudar, e cada
 * regra deste arquivo tapa um buraco que alguém tentaria na primeira
 * semana. O comentário de cada uma diz qual.
 *
 * O ponto NUNCA nasce do clique no botão: nasce do channel.raid, que é o
 * aviso da Twitch de que o raid aconteteceu mesmo, com quantos
 * espectadores foram. O cliente não tem como forjar isso — e de quebra,
 * raid dado direto pela Twitch conta igual.
 */

/** Quantos pontos valem um dia de Pro. */
const RAID_PONTOS_DIA = 14;

/** Raid pra quem usa o ZocaHub vale o dobro. */
const RAID_PONTOS_CASA = 2;
const RAID_PONTOS_FORA = 1;

/** Abaixo disto não conta: "live" de uma pessoa só não é raid. */
const RAID_MIN_ESPECTADORES = 3;

/** A live precisa ter durado isto pra valer. */
const RAID_MIN_HORAS = 2;

/** Teto de dias ganhos por mês. SEM ISTO O RECURSO VIRA PREJUÍZO. */
const RAID_DIAS_MES = 10;

/** Quantos alvos diferentes antes de repetir o mesmo. */
const RAID_REVEZAR = 2;

/**
 * O saldo desta pessoa, já com o mês certo.
 *
 * O mês mora junto do contador: virou o mês, a conta recomeça na primeira
 * leitura, e nenhum cron precisa existir pra isso.
 */
function raid_saldo(int $uid): array
{
    $mes = date('Y-m');
    $zero = ['pontos' => 0, 'dias' => 0, 'dias_mes' => 0, 'mes' => $mes, 'desconto' => 0];
    try {
        $st = db()->prepare('SELECT * FROM raid_saldo WHERE usuario_id = ?');
        $st->execute([$uid]);
        $s = $st->fetch();
    } catch (Throwable $e) {
        return $zero;
    }
    if (!$s) return $zero;
    if ($s['mes'] !== $mes) { $s['mes'] = $mes; $s['dias_mes'] = 0; }
    return $s;
}

/**
 * Por que este raid não vale ponto, ou '' quando vale.
 *
 * Separado do resto de propósito: é a lista de fraudes, e ela precisa ser
 * lida de uma vez pra alguém conferir se falta alguma.
 */
function raid_recusa(int $uid, string $alvoId, int $espectadores): string
{
    /* "Live" sozinho não é raid: sem isto, dá pra transmitir pra ninguém e
       raidar o dia inteiro. */
    if ($espectadores < RAID_MIN_ESPECTADORES) return 'poucos-espectadores';

    /* Duas horas no ar. Quem liga o OBS e raida no minuto seguinte está
       fazendo ponto, não live. */
    try {
        $st = db()->prepare('SELECT ao_vivo_desde FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $desde = $st->fetchColumn();
    } catch (Throwable $e) {
        return 'sem-tabela';
    }
    if (!$desde) return 'sem-live';
    if (time() - strtotime((string) $desde) < RAID_MIN_HORAS * 3600) return 'live-curta';

    /* O RODÍZIO, QUE É O QUE MATA A PANELINHA.

       Duas contas se raidando pra sempre era a fraude mais óbvia. "Uma vez
       por semana" não resolveria: bastaria esperar o relógio virar. Aqui o
       mesmo alvo só conta de novo depois de OUTROS DOIS alvos diferentes —
       não há relógio pra esperar, só gente de verdade pra raidar. */
    try {
        $st = db()->prepare(
            'SELECT alvo_id FROM raid_feitos
              WHERE usuario_id = ? AND pontos > 0
              ORDER BY id DESC LIMIT ' . RAID_REVEZAR
        );
        $st->execute([$uid]);
        if (in_array($alvoId, $st->fetchAll(PDO::FETCH_COLUMN), true)) return 'sem-revezar';
    } catch (Throwable $e) {
        return 'sem-tabela';
    }

    return '';
}

/** O alvo usa o ZocaHub e transmitiu nos últimos 30 dias? */
function raid_alvo_da_casa(string $alvoId): bool
{
    /* Só "tem conta" não basta: senão bastava cadastrar contas fantasma
       pra elas valerem o dobro. Tem que ter transmitido de verdade. */
    try {
        $st = db()->prepare(
            'SELECT 1 FROM usuarios
              WHERE twitch_user_id = ? AND ao_vivo_desde > DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1'
        );
        $st->execute([$alvoId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Um raid aconteceu. Conta, ou anota por que não contou.
 *
 * Grava SEMPRE, com ponto ou sem: a linha é a resposta pra quando a pessoa
 * reclamar no suporte que o raid dela não somou.
 */
function raid_registrar(int $uid, string $alvoId, string $alvoLogin, int $espectadores): void
{
    $motivo = raid_recusa($uid, $alvoId, $espectadores);
    $pontos = 0;

    if ($motivo === '') {
        $pontos = raid_alvo_da_casa($alvoId) ? RAID_PONTOS_CASA : RAID_PONTOS_FORA;
    }

    try {
        db()->prepare(
            'INSERT INTO raid_feitos (usuario_id, alvo_id, alvo_login, espectadores, pontos, motivo)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$uid, $alvoId, mb_substr($alvoLogin, 0, 30), $espectadores, $pontos, $motivo]);
    } catch (Throwable $e) {
        return;
    }

    if ($pontos === 0) return;

    $s = raid_saldo($uid);
    $total = (int) $s['pontos'] + $pontos;

    /* Os pontos viram dias, e o resto fica pro próximo. */
    $novosDias = intdiv($total, RAID_PONTOS_DIA);
    $sobra = $total % RAID_PONTOS_DIA;

    /* O TETO MENSAL É OBRIGATÓRIO. Sem ele, alguém dedicado tira Pro
       vitalício de graça e o recurso deixa de ser marketing. O que passar
       do teto fica nos pontos, e entra no mês que vem. */
    $cabe = max(0, RAID_DIAS_MES - (int) $s['dias_mes']);
    $daPra = min($novosDias, $cabe);
    if ($daPra < $novosDias) {
        $sobra += ($novosDias - $daPra) * RAID_PONTOS_DIA;
    }

    try {
        db()->prepare(
            'INSERT INTO raid_saldo (usuario_id, pontos, dias, dias_mes, mes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE pontos = VALUES(pontos), dias = dias + ' . (int) $daPra . ',
                 dias_mes = VALUES(dias_mes), mes = VALUES(mes)'
        )->execute([$uid, $sobra, $daPra, (int) $s['dias_mes'] + $daPra, $s['mes']]);
    } catch (Throwable $e) { /* o raid já está registrado */ }
}
