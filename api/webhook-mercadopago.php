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
require_once __DIR__ . '/lib/mercadopago.php';

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

/* O aviso trouxe um id. Agora perguntamos ao Mercado Pago o que esse id é de
   verdade — e é ESTA resposta que decide, não o corpo que chegou. Um webhook
   diz que algo mudou; ele não diz a verdade do que mudou. */
$falha = null;

try {
    $tipo = (string) ($dados['type'] ?? ($_GET['type'] ?? ''));

    /* Só pagamento interessa. Os outros avisos (merchant_order e afins)
       chegam pelo mesmo canal, e tratar todos daria trabalho para nada. */
    if ($tipo !== '' && $tipo !== 'payment') {
        throw new RuntimeException('ignorado: tipo ' . $tipo);
    }

    $pag = mp_ler_pagamento($data_id);
    if ($pag === null) {
        throw new RuntimeException('o Mercado Pago não respondeu sobre o pagamento ' . $data_id);
    }

    $situacao = (string) ($pag['status'] ?? '');
    $ref      = (string) ($pag['external_reference'] ?? '');

    /* DINHEIRO QUE VOLTA TIRA O ACESSO.

       'refunded' é estorno, total ou parcial; 'charged_back' é contestação
       no cartão. Nos dois casos o dinheiro saiu da conta, e manter o Pro
       ligado seria entregar o produto de graça pra quem pediu reembolso. */
    if ($situacao === 'refunded' || $situacao === 'charged_back' || $situacao === 'cancelled') {
        $ref = (string) ($pag['external_reference'] ?? '');
        if ($ref !== '') mp_estornar($ref, $situacao);
        throw new RuntimeException('tratado: acesso retirado por ' . $situacao);
    }

    if ($situacao !== 'approved') {
        /* Pendente e recusado não são erro: o Pix aprovado chega depois no
           mesmo canal. Guardar como tratado evita reprocessar pra sempre. */
        throw new RuntimeException('ignorado: pagamento ' . ($situacao ?: 'sem status'));
    }

    $usuario_id = mp_referencia_usuario($ref);
    if (!$usuario_id) {
        throw new RuntimeException('referência sem dono: ' . $ref);
    }

    /* De qual plano era. A referência carrega o slug, mas quem manda é a
       linha que o checkout gravou — ela é nossa e ninguém de fora escreve
       nela. A referência só serve de reserva. */
    $st = db()->prepare('SELECT plano_id FROM assinaturas WHERE referencia = ? LIMIT 1');
    $st->execute([$ref]);
    $plano_id = (int) $st->fetchColumn();

    $plano = $plano_id ? mp_plano_por_id($plano_id) : null;
    if (!$plano && preg_match('/^zc-\d+-([a-z0-9_]+)-/', $ref, $m)) {
        $plano = mp_plano_por_slug($m[1]);
    }
    if (!$plano) {
        throw new RuntimeException('não achei o plano da referência ' . $ref);
    }

    $centavos = (int) round(((float) ($pag['transaction_amount'] ?? 0)) * 100);
    mp_liberar($usuario_id, $plano, $ref, (string) ($pag['id'] ?? $data_id), $centavos);

} catch (Throwable $e) {
    $falha = $e->getMessage();
}

/* O diário fecha sempre, com o motivo quando não deu. Sem isto, um pagamento
   que não liberou vira um mistério sem rastro — e é justamente o caso em que
   alguém pagou e está sem o que comprou. */
db()->prepare('UPDATE eventos_pagamento SET processado_em = NOW(), erro = ? WHERE id = ?')
    ->execute([$falha, $evento_pk]);
