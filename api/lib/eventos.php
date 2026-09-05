<?php
/**
 * O diário de eventos: sub, follow, bits, doação.
 *
 * É o que alimenta o feed na tela. Fica separado do livro-caixa do subathon
 * porque o feed tem que funcionar pra quem nunca fez subathon nenhum.
 */
require_once __DIR__ . '/db.php';

/**
 * Anota um evento. Repetido é ignorado em silêncio: o chat reentrega
 * mensagem quando a conexão cai, e a Twitch entrega "pelo menos uma vez".
 */
function evento_registrar(int $usuario_id, array $d): void
{
    $chave = mb_substr(trim((string) ($d['chave'] ?? '')), 0, 120);
    if ($chave === '') return;

    try {
        db()->prepare(
            'INSERT INTO eventos (usuario_id, chave, tipo, quem, quantidade, detalhe)
                  VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $usuario_id, $chave,
            mb_substr((string) ($d['tipo'] ?? ''), 0, 16),
            mb_substr((string) ($d['quem'] ?? ''), 0, 64) ?: null,
            max(1, (int) ($d['quantidade'] ?? 1)),
            mb_substr((string) ($d['detalhe'] ?? ''), 0, 64) ?: null,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e;   /* repetido: tudo bem */
    }
}

/**
 * Os últimos eventos, prontos pra desenhar.
 *
 * JUNTA OS PRESENTES. Um pacote de 10 subs de presente chega como 10 eventos
 * separados — é assim que a Twitch manda. Sem juntar, o feed vira dez linhas
 * iguais e empurra todo o resto pra fora da tela. Presentes da mesma pessoa
 * dentro de 2 minutos viram uma linha só com a conta.
 */
function evento_recentes(int $usuario_id, int $quantos = 12): array
{
    /* O id vem junto por causa do ALERTA: o feed mostra os últimos e pronto,
       mas o alerta precisa saber o que ele JÁ mostrou, senão toda recarga da
       fonte no OBS dispararia de novo a fila inteira de subs do dia. */
    $st = db()->prepare(
        'SELECT id, tipo, quem, quantidade, detalhe,
                UNIX_TIMESTAMP(criado_em) AS quando
           FROM eventos WHERE usuario_id = ? ORDER BY id DESC LIMIT 120'
    );
    $st->execute([$usuario_id]);

    $saida = [];
    foreach ($st->fetchAll() as $e) {
        $ehPresente = ($e['detalhe'] ?? '') !== '' && str_starts_with((string) $e['detalhe'], 'presente');
        $ultimo = $saida ? $saida[count($saida) - 1] : null;

        if ($ehPresente && $ultimo && !empty($ultimo['presente'])
            && $ultimo['quem'] === $e['quem']
            && abs((int) $ultimo['quando'] - (int) $e['quando']) <= 120) {
            $saida[count($saida) - 1]['quantidade'] += (int) $e['quantidade'];
            continue;
        }

        $saida[] = [
            /* Num pacote de presentes juntado, vale o id do mais novo: é o que
               o alerta compara pra saber se o pacote inteiro já passou. */
            'id'         => (int) $e['id'],
            'tipo'       => $e['tipo'],
            'quem'       => $e['quem'] ?: 'alguém',
            'quantidade' => (int) $e['quantidade'],
            'presente'   => $ehPresente,
            'quando'     => (int) $e['quando'],
        ];
        if (count($saida) >= $quantos) break;
    }
    return $saida;
}
