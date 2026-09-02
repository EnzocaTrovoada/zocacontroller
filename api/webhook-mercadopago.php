<?php
/**
 * Recebe avisos de cobrança do Mercado Pago.
 *
 * Regra de ouro: o webhook diz QUE algo mudou, não diz A VERDADE do que mudou.
 * Ele traz um id; quem confirma o estado é a consulta na API deles.
 * Confiar no corpo da notificação é liberar acesso porque alguém disse que pagou.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/seguranca.php';

$corpo = file_get_contents('php://input');
$dados = json_decode($corpo, true) ?: [];

$sig        = $_SERVER['HTTP_X_SIGNATURE']  ?? '';
$request_id = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
$data_id    = (string) ($dados['data']['id'] ?? ($_GET['data.id'] ?? ''));

// 1. Autenticidade antes de qualquer coisa.
if ($data_id === '' || !mp_webhook_valido($sig, $request_id, $data_id)) {
    http_response_code(401);
    exit;
}

// 2. Idempotência: o mesmo aviso chega mais de uma vez, sempre.
//    O UNIQUE do banco resolve isso sem precisar de trava.
$evento_id = $request_id !== '' ? $request_id : $data_id;

try {
    $st = db()->prepare(
        'INSERT INTO eventos_pagamento (provedor, evento_id, tipo, payload) VALUES (?, ?, ?, ?)'
    );
    $st->execute(['mercadopago', $evento_id, $dados['type'] ?? 'desconhecido', $corpo]);
    $evento_pk = (int) db()->lastInsertId();
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {       // duplicado: já tratamos
        http_response_code(200);
        exit('ok');
    }
    throw $e;
}

// 3. Responder rápido — se demorar, eles reenviam e viram vários eventos iguais.
http_response_code(200);
echo 'ok';
responder_e_continuar();

// 4. Daqui pra baixo o cliente já foi embora.
//
// TODO consultar GET /preapproval/{id} (ou /v1/payments/{id}) com o access_token
//      e só então atualizar assinaturas.status e assinaturas.valido_ate.
// TODO marcar eventos_pagamento.processado_em, ou gravar a falha em .erro
//      para uma rotina de reprocessamento pegar depois.
