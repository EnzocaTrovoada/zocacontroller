<?php
/**
 * Cupons de desconto e comissão de parceiro.
 *
 * O desconto é aplicado no NOSSO lado: a gente valida o código, calcula o
 * preço abatido e monta a cobrança já com ele. O Mercado Pago tem um campo
 * de cupom na tela deles, mas aquele é o sistema deles, com as promoções
 * deles — código nosso não entra lá.
 *
 * A COMISSÃO SÓ NASCE COM O PAGAMENTO APROVADO, nunca na hora de abrir o
 * checkout. Metade das cobranças abertas nunca é paga; contar comissão
 * antes seria criar dívida com parceiro por venda que não aconteceu.
 *
 * Pelo mesmo motivo o contador de usos do cupom sobe na aprovação: um
 * cupom de dez usos não pode se esgotar com dez pessoas que só abriram a
 * tela e desistiram.
 */
require_once __DIR__ . '/db.php';

/** Nunca deixar o preço chegar a zero: cobrança de R$ 0 o gateway recusa. */
const CUPOM_MINIMO_CENTAVOS = 100;

function cupom_limpa(string $codigo): string
{
    return mb_strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $codigo));
}

/**
 * O cupom vale agora?
 *
 * Devolve ['ok' => bool, 'erro' => string, 'cupom' => array].
 * A mensagem de erro é escrita pra quem digitou, não pra quem programa:
 * "esse código já venceu" resolve, "invalid coupon" não.
 */
function cupom_valida(string $codigo): array
{
    $codigo = cupom_limpa($codigo);
    if ($codigo === '') return ['ok' => false, 'erro' => 'Escreva o código.'];

    try {
        $st = db()->prepare('SELECT * FROM cupons WHERE codigo = ? LIMIT 1');
        $st->execute([$codigo]);
        $c = $st->fetch();
    } catch (Throwable $e) {
        /* Tabela nova: quem não rodou o SQL não pode ver o checkout quebrar
           por causa de um campo opcional. */
        return ['ok' => false, 'erro' => 'Os cupons ainda não estão ativos.'];
    }

    if (!$c)                  return ['ok' => false, 'erro' => 'Esse código não existe.'];
    if (!$c['ligado'])        return ['ok' => false, 'erro' => 'Esse código não está mais valendo.'];

    if ($c['vale_ate'] !== null && strtotime((string) $c['vale_ate']) < time()) {
        return ['ok' => false, 'erro' => 'Esse código venceu em '
            . date('d/m/Y', strtotime((string) $c['vale_ate'])) . '.'];
    }

    if ($c['usos_max'] !== null && (int) $c['usos'] >= (int) $c['usos_max']) {
        return ['ok' => false, 'erro' => 'Esse código já foi usado o máximo de vezes.'];
    }

    return ['ok' => true, 'cupom' => $c];
}

/**
 * Quanto fica o preço com o desconto.
 *
 * Sempre devolve um valor cobrável: desconto que zeraria a conta é cortado
 * no mínimo, porque cobrança de zero o gateway recusa e a pessoa fica sem
 * entender o que houve.
 */
function cupom_aplica(array $cupom, int $centavos): array
{
    if ($cupom['tipo'] === 'percentual') {
        $abate = (int) round($centavos * min(100, (int) $cupom['valor']) / 100);
    } else {
        $abate = min($centavos, (int) $cupom['valor']);
    }

    $novo = max(CUPOM_MINIMO_CENTAVOS, $centavos - $abate);

    return [
        'de'       => $centavos,
        'por'      => $novo,
        'desconto' => $centavos - $novo,
        'rotulo'   => $cupom['tipo'] === 'percentual'
            ? ((int) $cupom['valor']) . '% de desconto'
            : 'R$ ' . number_format(((int) $cupom['valor']) / 100, 2, ',', '.') . ' de desconto',
    ];
}

/**
 * Registra o uso e, se o cupom tem dono, a comissão que ele gerou.
 *
 * Chamado UMA vez por venda aprovada. A proteção contra chamar duas vezes
 * é o UNIQUE em comissoes.assinatura_id — o webhook do Mercado Pago
 * reentrega o mesmo aviso, e sem isso cada reentrega viraria dívida nova
 * com o parceiro.
 */
function cupom_registra_venda(string $codigo, int $assinatura_id, int $pago_centavos): void
{
    $codigo = cupom_limpa($codigo);
    if ($codigo === '' || $assinatura_id <= 0) return;

    try {
        $st = db()->prepare('SELECT * FROM cupons WHERE codigo = ? LIMIT 1');
        $st->execute([$codigo]);
        $c = $st->fetch();
        if (!$c) return;

        db()->prepare('UPDATE cupons SET usos = usos + 1 WHERE codigo = ?')->execute([$codigo]);

        if (empty($c['parceiro_id'])) return;

        $pt = db()->prepare('SELECT comissao_pct, ligado FROM parceiros WHERE id = ?');
        $pt->execute([(int) $c['parceiro_id']]);
        $p = $pt->fetch();
        if (!$p || !$p['ligado']) return;

        /* A comissão incide sobre o que ENTROU, não sobre o preço de tabela.
           Se o parceiro deu 20% de desconto, ele não pode receber comissão
           sobre um valor que ninguém pagou. */
        $valor = (int) round($pago_centavos * ((float) $p['comissao_pct']) / 100);
        if ($valor <= 0) return;

        db()->prepare(
            'INSERT INTO comissoes (parceiro_id, assinatura_id, codigo, base_centavos, valor_centavos)
                  VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE valor_centavos = VALUES(valor_centavos)'
        )->execute([(int) $c['parceiro_id'], $assinatura_id, $codigo, $pago_centavos, $valor]);

    } catch (Throwable $e) {
        /* Comissão é contabilidade nossa: falhar aqui não pode impedir a
           pessoa que pagou de receber o que comprou. Fica sem registro, e
           o relatório do admin mostra a venda com cupom e sem comissão. */
    }
}

/**
 * Dinheiro que voltou também volta a comissão.
 *
 * Marcar em vez de apagar: o parceiro pode já ter visto aquela venda no
 * relatório dele, e sumir com a linha é pior do que mostrá-la estornada.
 */
function cupom_estorna_venda(int $assinatura_id): void
{
    try {
        db()->prepare('UPDATE comissoes SET estornada = 1 WHERE assinatura_id = ?')
            ->execute([$assinatura_id]);
    } catch (Throwable $e) { /* tabela nova */ }
}
