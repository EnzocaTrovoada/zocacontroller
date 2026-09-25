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
    /* RECUSAR CALADO ERA O PIOR DOS DOIS MUNDOS.

       Nada daqui é processado — aviso sem assinatura válida não libera nada,
       e o conteýdo dele é texto de terceiro, nunca instrução. Mas sumir sem
       deixar rastro esconde justamente o caso que dói: se a assinatura de
       alguém parar de renovar porque as notificações começaram a chegar sem
       assinatura válida, isso tem que APARECER na tela de erros, e não ser
       descoberto pelo cliente reclamando que perdeu o Pro.

       Só o motivo é anotado. A tabela de erros junta por arquivo e linha, então
       quem insistir vira uma linha só com o contador subindo. */
    erro_anota(new RuntimeException(
        'webhook recusado: ' . ($data_id === '' ? 'sem id' : 'assinatura inválida')
    ));
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
/**
 * De qual plano é uma referência nossa.
 *
 * Quem manda é a linha que o checkout gravou — ela é nossa e ninguém de fora
 * escreve nela. O slug dentro da referência só serve de reserva, pro caso da
 * linha ter sumido.
 */
function zc_plano_da_referencia(string $ref): array
{
    $st = db()->prepare('SELECT plano_id FROM assinaturas WHERE referencia = ? LIMIT 1');
    $st->execute([$ref]);
    $plano_id = (int) $st->fetchColumn();

    $plano = $plano_id ? mp_plano_por_id($plano_id) : null;
    if (!$plano && preg_match('/^zc-\d+-([a-z0-9_]+)-/', $ref, $m)) {
        $plano = mp_plano_por_slug($m[1]);
    }
    if (!$plano) throw new RuntimeException('não achei o plano da referência ' . $ref);

    return $plano;
}

$falha = null;

try {
    $tipo = (string) ($dados['type'] ?? ($_GET['type'] ?? ''));

    /* ---------- a assinatura mudou de estado ----------

       Chega quando alguém autoriza, pausa ou cancela. NÃO libera acesso: quem
       libera é a cobrança. O que acontece aqui é anotar se ainda renova, e
       entregar o período grátis quando ele existe. */
    if ($tipo === 'subscription_preapproval') {
        $as = mp_ler_assinatura($data_id);
        if ($as === null) {
            throw new RuntimeException('o Mercado Pago não respondeu sobre a assinatura ' . $data_id);
        }

        $sit = (string) ($as['status'] ?? '');
        mp_assinatura_estado($data_id, $sit);

        if ($sit === 'authorized') {
            mp_assinatura_comeco($data_id, (string) ($as['next_payment_date'] ?? ''));
        }

        throw new RuntimeException('tratado: assinatura ' . ($sit ?: 'sem status'));
    }

    /* ---------- a cobrança do mês ----------

       É ISTO QUE FAZ A RENOVAÇÃO ACONTECER. Todo mês o Mercado Pago gera uma
       fatura da assinatura, tenta cobrar, e avisa aqui. A fatura é quem sabe de
       qual assinatura ela é — o pagamento sozinho não diz. */
    if ($tipo === 'subscription_authorized_payment') {
        $fat = mp_ler_fatura($data_id);
        if ($fat === null) {
            throw new RuntimeException('o Mercado Pago não respondeu sobre a fatura ' . $data_id);
        }

        $sitPag = (string) ($fat['payment']['status'] ?? '');
        if ($sitPag !== 'approved') {
            /* Cobrança que falhou não é erro nosso: eles tentam de novo
               sozinhos por alguns dias, e cada tentativa avisa aqui. O acesso
               continua valendo até a data que já foi paga. */
            throw new RuntimeException('ignorado: cobrança ' . ($sitPag ?: 'sem status'));
        }

        /* A referência pode vir na fatura ou só na assinatura. Tentar as duas é
           o que impede uma renovação de se perder por um campo vazio. */
        $ref = (string) ($fat['external_reference'] ?? '');
        if ($ref === '') {
            $as = mp_ler_assinatura((string) ($fat['preapproval_id'] ?? ''));
            $ref = (string) ($as['external_reference'] ?? '');
        }

        $usuario_id = mp_referencia_usuario($ref);
        if (!$usuario_id) throw new RuntimeException('referência sem dono: ' . $ref);

        $centavos = (int) round(((float) ($fat['transaction_amount'] ?? 0)) * 100);

        mp_liberar(
            $usuario_id,
            zc_plano_da_referencia($ref),
            $ref,
            (string) ($fat['payment']['id'] ?? $data_id),
            $centavos
        );

        throw new RuntimeException('tratado: renovação de ' . $ref);
    }

    /* Dos avisos que sobram, só pagamento interessa. Os outros
       (merchant_order e afins) chegam pelo mesmo canal e não dizem nada que
       a gente já não saiba pelos de cima. */
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

    $plano = zc_plano_da_referencia($ref);

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
