<?php
/**
 * Manda o usuário para o checkout HOSPEDADO do Mercado Pago.
 *
 * O cartão é digitado no domínio DELES, nunca no nosso. Nosso servidor não vê,
 * não trafega e não guarda número de cartão — e é isso que mantém a gente fora
 * do escopo pesado do PCI DSS. Nunca embutir campo de cartão em página nossa,
 * nem em iframe: isso já muda o questionário de SAQ A para SAQ A-EP.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/seguranca.php';

// TODO exigir usuário logado (OAuth da Twitch) e ler $usuario_id da sessão.
$usuario_id = 0;
$plano_slug = $_GET['plano'] ?? 'pro';

$st = db()->prepare('SELECT * FROM planos WHERE slug = ? LIMIT 1');
$st->execute([$plano_slug]);
$plano = $st->fetch();

if (!$plano) {
    json_saida(['erro' => 'Plano não encontrado.'], 404);
}

// TODO POST https://api.mercadopago.com/preapproval
//      Header: Authorization: Bearer <access_token>
//      Corpo:  reason, external_reference = usuario_id, back_url = url_retorno,
//              auto_recurring { frequency: 1, frequency_type: "months",
//                               transaction_amount: preco_centavos / 100,
//                               currency_id: "BRL" }
//
// A resposta traz init_point (a URL do checkout deles) e o id do preapproval.
//
// Gravar em assinaturas com status 'pendente' ANTES de redirecionar — assim o
// webhook encontra a linha quando o pagamento voltar. E nunca liberar acesso na
// volta do navegador: essa URL qualquer um digita.

// header('Location: ' . $init_point);
// exit;
