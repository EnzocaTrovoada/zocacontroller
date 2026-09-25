<?php
/**
 * Cobrança pelo Mercado Pago.
 *
 * POR QUE PAGAMENTO AVULSO E NÃO ASSINATURA RECORRENTE:
 *
 * A recorrência do Mercado Pago (preapproval) só aceita cartão. Pix não
 * pode ser recorrente — não existe autorização prévia de Pix comum, e o
 * público daqui é streamer brasileiro pequeno, onde muita gente não tem
 * cartão de crédito. Cobrar por Checkout Pro avulso aceita Pix, cartão e
 * boleto, e cada pagamento empurra a validade pra frente.
 *
 * O custo dessa escolha é honesto: ninguém é cobrado sozinho no mês
 * seguinte, então quem esquece de renovar cai pro plano grátis. Trocar isso
 * por recorrência depois é possível e não joga fora nada daqui: muda a
 * chamada que cria a cobrança, o resto continua igual.
 *
 * O CARTÃO NUNCA PASSA POR AQUI. A pessoa digita no domínio do Mercado
 * Pago. Nosso servidor não vê, não trafega e não guarda número de cartão, e
 * é isso que mantém a gente fora do escopo pesado do PCI DSS. Nunca embutir
 * campo de cartão em página nossa, nem dentro de iframe.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cupons.php';

const MP_API = 'https://api.mercadopago.com';

/** Quantos dias cada período vale. O vitalício não tem dias — tem data. */
const MP_DIAS = ['mensal' => 30, 'anual' => 365];

/* A DATA DO VITALÍCIO.

   Uma data distante em vez de NULL de propósito: 'valido_ate IS NULL' já quer
   dizer "nunca teve acesso" em acesso_do_usuario(), e reaproveitar o mesmo
   NULL pra dizer o contrário ("acesso pra sempre") faria as duas situações
   opostas passarem pelo mesmo IF. Com data, toda consulta que já existe
   continua valendo sem precisar aprender um caso novo. */
const MP_VITALICIO_ATE = '2099-12-31 23:59:59';

/**
 * A configuração, com os padrões seguros.
 *
 * 'ligado' nasce FALSO. A estrutura inteira pode estar pronta e no ar sem
 * que exista botão de pagar em lugar nenhum — que é exatamente o estado em
 * que ela deve ficar até o teste de ponta a ponta passar.
 */
function mp_cfg(): array
{
    $c = cfg()['mercadopago'] ?? [];
    return [
        'ligado'         => !empty($c['ligado']),
        'modo'           => ($c['modo'] ?? 'teste') === 'producao' ? 'producao' : 'teste',
        'access_token'   => (string) ($c['access_token'] ?? ''),
        'webhook_secret' => (string) ($c['webhook_secret'] ?? ''),
        'url_retorno'    => (string) ($c['url_retorno'] ?? ''),
    ];
}

function mp_http(string $metodo, string $caminho, ?array $corpo = null, array $extras = []): array
{
    $mp = mp_cfg();
    if ($mp['access_token'] === '') {
        throw new RuntimeException('Falta o access_token do Mercado Pago no config.php.');
    }

    $cab = array_merge([
        'Authorization: Bearer ' . $mp['access_token'],
        'Content-Type: application/json',
    ], $extras);

    $ch = curl_init(MP_API . $caminho);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $cab,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }

    $resposta = curl_exec($ch);
    if ($resposta === false) {
        $erro = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Não consegui falar com o Mercado Pago: ' . $erro);
    }
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$codigo, json_decode($resposta, true)];
}

/**
 * A referência que amarra as duas pontas.
 *
 * O checkout cria uma cobrança e o aviso volta minutos depois falando de um
 * PAGAMENTO — dois ids diferentes, do lado deles. Esta string é o que liga
 * um ao outro, e ela vai no external_reference, que volta na consulta.
 *
 * O sorteio no fim existe porque a mesma pessoa pode abrir o checkout duas
 * vezes: sem ele, as duas tentativas dividiriam a mesma referência e o
 * segundo pagamento acharia a linha do primeiro.
 */
function mp_referencia(int $usuario_id, string $plano_slug): string
{
    return 'zc-' . $usuario_id . '-' . $plano_slug . '-' . bin2hex(random_bytes(6));
}

function mp_referencia_usuario(string $referencia): ?int
{
    if (!preg_match('/^zc-(\d+)-/', $referencia, $m)) return null;
    return (int) $m[1];
}

/**
 * Cria a cobrança e devolve para onde mandar a pessoa.
 *
 * Grava a linha como 'pendente' ANTES de devolver a URL. Se gravasse depois,
 * um pagamento muito rápido (Pix é imediato) poderia trazer o aviso antes da
 * linha existir — e o aviso não teria onde encaixar.
 */
/** Quantos dias de Pro uma pessoa pode gastar por mês. */
const MP_DIAS_MES = 5;

/**
 * Tira do preço os dias ganhos raidando, respeitando o teto.
 *
 * Um dia vale um dia do plano MENSAL, sempre — inclusive quando se está
 * comprando o anual. Valer "um dia do plano que está comprando" faria o
 * mesmo esforço valer dez vezes menos no anual, o que ninguém entenderia.
 */
function mp_menos_dias(int $usuario_id, array $plano, int $centavos, int &$usados): int
{
    $usados = 0;

    try {
        $st = db()->prepare('SELECT dias, desconto FROM raid_saldo WHERE usuario_id = ?');
        $st->execute([$usuario_id]);
        $s = $st->fetch();
    } catch (Throwable $e) {
        return $centavos;                    /* sem o SQL 063: ninguém tem dias */
    }

    if (!$s || !(int) $s['desconto'] || (int) $s['dias'] < 1) return $centavos;

    try {
        $m = db()->query("SELECT preco_centavos FROM planos
                           WHERE periodo = 'mensal' AND preco_centavos > 0 LIMIT 1");
        $mensal = (int) $m->fetchColumn();
    } catch (Throwable $e) {
        return $centavos;
    }
    if ($mensal < 1) return $centavos;

    $usados = min(MP_DIAS_MES, (int) $s['dias']);
    $tira = (int) round($usados * ($mensal / 30));

    /* NO MÁXIMO METADE DA FATURA. Com cupom em cima, os dois juntos
       poderiam zerar a cobrança — e cobrança de zero real é pedido que o
       Mercado Pago recusa, com a pessoa achando que o site quebrou. */
    $teto = intdiv($centavos, 2);
    if ($tira > $teto) {
        $tira = $teto;
        /* Gastar só o que coube: o resto continua no saldo. */
        $usados = $mensal > 0 ? (int) floor($tira / ($mensal / 30)) : 0;
    }

    return max(1, $centavos - $tira);
}

function mp_criar_cobranca(int $usuario_id, array $plano, string $codigo = ''): array
{
    $mp = mp_cfg();
    $ref = mp_referencia($usuario_id, (string) $plano['slug']);

    $centavos = (int) $plano['preco_centavos'];
    $cupom = '';

    /* O desconto entra AQUI, no preço que vai pro Mercado Pago. Guardar o
       código na linha da assinatura é o que permite, na aprovação, saber
       qual parceiro indicou esta venda. */
    if ($codigo !== '') {
        $v = cupom_valida($codigo);
        if (!empty($v['ok'])) {
            $conta = cupom_aplica($v['cupom'], $centavos);
            $centavos = $conta['por'];
            $cupom = cupom_limpa($codigo);
        }
    }

    /* ---------- os dias ganhos raidando ----------

       ACUMULAR É ILIMITADO; GASTAR É QUE TEM TETO. A pessoa junta quantos
       dias quiser, mas só 5 por mês viram desconto — e só se ela tiver
       ligado o modo desconto, porque tem quem prefira guardar pra quando
       parar de pagar.

       A CONTA SAI DAQUI, NO SERVIDOR, e nunca chega do navegador: o prêmio
       é dinheiro, e número que vem do cliente é número que se troca.

       Os dias NÃO são gastos agora. Eles saem do saldo quando o pagamento
       é aprovado, lá no webhook — senão dois checkouts abertos gastariam o
       mesmo saldo duas vezes, e um checkout abandonado gastaria à toa. */
    $diasUsados = 0;
    $centavos = mp_menos_dias($usuario_id, $plano, $centavos, $diasUsados);

    $valor = $centavos / 100;

    $corpo = [
        'items' => [[
            'id'          => (string) $plano['slug'],
            'title'       => 'ZocaController — ' . $plano['nome']
                             . ($cupom !== '' ? ' (cupom ' . $cupom . ')' : '')
                             . ($diasUsados > 0 ? ' (-' . $diasUsados . ' dias de raid)' : ''),
            'quantity'    => 1,
            'currency_id' => 'BRL',
            'unit_price'  => $valor,
        ]],
        'external_reference' => $ref,
        'notification_url'   => api_base() . '/webhook-mercadopago.php',
        /* Boleto fica de fora: ele demora dias pra compensar e o streamer
           que pagou fica sem o recurso achando que o site quebrou. */
        'payment_methods' => [
            'excluded_payment_types' => [['id' => 'ticket']],
            'installments' => 1,
        ],
    ];
    if ($mp['url_retorno'] !== '') {
        $corpo['back_urls'] = [
            'success' => $mp['url_retorno'] . '?r=ok',
            'pending' => $mp['url_retorno'] . '?r=processando',
            'failure' => $mp['url_retorno'] . '?r=falhou',
        ];
        $corpo['auto_return'] = 'approved';
    }

    [$http, $r] = mp_http('POST', '/checkout/preferences', $corpo);
    if ($http !== 201 && $http !== 200) {
        /* O corpo da recusa fica no registro do servidor: ele vai pra tela de
           quem está pagando, e lá só serve a frase. O porquê, o admin vê no
           checkout.php?diagnostico. */
        error_log('[zc] Mercado Pago recusou a cobrança (' . $http . '): '
            . (is_array($r) ? json_encode($r, JSON_UNESCAPED_UNICODE) : 'resposta vazia'));
        throw new RuntimeException('O Mercado Pago recusou a cobrança agora. Tente de novo em alguns minutos.');
    }

    db()->prepare(
        'INSERT INTO assinaturas (usuario_id, plano_id, provedor, provedor_id, referencia, status, teste, cupom, dias_raid)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $usuario_id, (int) $plano['id'], 'mercadopago',
        (string) $r['id'], $ref, 'pendente', $mp['modo'] === 'teste' ? 1 : 0,
        $cupom !== '' ? $cupom : null,
        /* Anotado aqui, gasto só na aprovação: a linha é o que amarra este
           checkout aos dias que ele prometeu usar. */
        $diasUsados,
    ]);

    /* SEMPRE O init_point, NUNCA O sandbox_init_point.

       O Mercado Pago DESLIGOU o ambiente de sandbox. O sandbox_init_point
       ainda vem na resposta, mas o endereço sandbox.mercadopago.com.br
       entra em laço de redirecionamento e o navegador desiste com
       ERR_TOO_MANY_REDIRECTS — sem mensagem que ligue o erro à causa.

       Hoje teste e produção usam o MESMO endereço e a mesma API. O que
       separa os dois é só qual credencial está carregada, e mais nada. */
    $url = (string) ($r['init_point'] ?? '');

    return ['url' => $url, 'referencia' => $ref, 'preferencia' => (string) $r['id'],
            'cupom' => $cupom, 'centavos' => $centavos];
}

/* ==================== ASSINATURA QUE SE RENOVA SOZINHA ====================

   A cobrança avulsa acima vende TRINTA DIAS. Quando eles acabam, o acesso
   cai e a pessoa precisa lembrar de voltar e comprar de novo — e quase
   ninguém volta. Assinatura é o mesmo produto cobrado sozinho todo mês.

   O CARTÃO CONTINUA FORA DAQUI. O Mercado Pago tem dois jeitos de criar
   assinatura: com o cartão tokenizado pelo nosso site (status 'authorized',
   que exige formulário de cartão em página nossa) ou pendente, mandando a
   pessoa pro checkout HOSPEDADO deles (status 'pending'). Usamos o segundo:
   o primeiro tiraria o site do SAQ A do PCI, que é justamente o que o
   comentário no topo do checkout.php manda nunca fazer. */

/** De quanto em quanto tempo o Mercado Pago cobra, por plano nosso. */
const MP_RECORRENCIA = [
    'mensal' => [1,  'months'],
    'anual'  => [12, 'months'],
];

/** Plano que pode virar assinatura. O vitalício não pode: não há o que renovar. */
function mp_pode_assinar(array $plano): bool
{
    return isset(MP_RECORRENCIA[(string) ($plano['periodo'] ?? '')]);
}

/**
 * O desconto vira DIAS GRÁTIS antes da primeira cobrança.
 *
 * Na compra avulsa o desconto sai do preço. Em assinatura isso não serve: o
 * valor combinado é cobrado igual todo mês, e baixar o valor daria desconto
 * pra sempre — o cupom de 30% viraria 30% eterno, de graça, todo mês.
 *
 * Então o desconto anda no TEMPO. Quem tem 5 dias de raid e um cupom de
 * metade do mensal começa a pagar 20 dias depois. O valor mensal fica
 * intacto e o desconto acontece uma vez só, que é o que ele sempre foi.
 */
function mp_dias_gratis(int $usuario_id, array $plano, string $codigo, int &$diasRaid, string &$cupom): int
{
    $diasRaid = 0;
    $cupom = '';

    $cheio = (int) $plano['preco_centavos'];
    $doPeriodo = MP_DIAS[(string) $plano['periodo']] ?? 30;
    if ($cheio < 1 || $doPeriodo < 1) return 0;

    /* Quanto vale um dia DESTE plano. É a régua que converte qualquer
       desconto em centavos para dias. */
    $porDia = $cheio / $doPeriodo;
    $dias = 0;

    if ($codigo !== '') {
        $v = cupom_valida($codigo);
        if (!empty($v['ok'])) {
            $conta = cupom_aplica($v['cupom'], $cheio);
            $tira = max(0, $cheio - (int) $conta['por']);
            $dias += (int) floor($tira / $porDia);
            $cupom = cupom_limpa($codigo);
        }
    }

    /* Os dias de raid entram como dias, sem conversão: é a unidade em que
       eles já foram ganhos. O teto de 5 por mês e o modo desconto valem
       igual aqui, e a conta sai do servidor — nunca do navegador. */
    try {
        $st = db()->prepare('SELECT dias, desconto FROM raid_saldo WHERE usuario_id = ?');
        $st->execute([$usuario_id]);
        $s = $st->fetch();
        if ($s && (int) $s['desconto'] && (int) $s['dias'] > 0) {
            $diasRaid = min(MP_DIAS_MES, (int) $s['dias']);
            $dias += $diasRaid;
        }
    } catch (Throwable $e) { /* sem o SQL 063: ninguém tem dias */ }

    /* NO MÁXIMO METADE DO PERÍODO, pelo mesmo motivo do caminho avulso: o
       desconto é empurrão, não o produto. O que não coube fica no saldo. */
    $teto = intdiv($doPeriodo, 2);
    if ($dias > $teto) {
        $sobrou = $dias - $teto;
        $dias = $teto;
        $diasRaid = max(0, $diasRaid - $sobrou);
    }

    return $dias;
}

/**
 * Abre a assinatura e devolve o link do checkout hospedado.
 *
 * O E-MAIL É PEDIDO PORQUE O MERCADO PAGO EXIGE QUE ELE BATA.
 *
 * Em assinatura sem plano associado eles conferem, na hora do pagamento, se
 * o e-mail que mandamos é o mesmo da conta que está pagando; não batendo,
 * RECUSAM. Não dá pra usar o e-mail da Twitch: é outro cadastro, e na maior
 * parte das pessoas é outro endereço. Ou a pessoa digita o e-mail da conta
 * do Mercado Pago dela, ou a cobrança é recusada sem dizer por quê.
 */
function mp_criar_assinatura(int $usuario_id, array $plano, string $codigo, string $email): array
{
    $mp = mp_cfg();

    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Preciso do e-mail da sua conta do Mercado Pago pra abrir a assinatura.');
    }

    $rec = MP_RECORRENCIA[(string) $plano['periodo']] ?? null;
    if ($rec === null) throw new RuntimeException('Esse plano não é de assinatura.');

    $ref = mp_referencia($usuario_id, (string) $plano['slug']);

    $diasRaid = 0;
    $cupom = '';
    $gratis = mp_dias_gratis($usuario_id, $plano, $codigo, $diasRaid, $cupom);

    $centavos = (int) $plano['preco_centavos'];

    $corpo = [
        'reason'             => 'ZocaController — ' . $plano['nome'],
        'external_reference' => $ref,
        'payer_email'        => $email,
        'back_url'           => $mp['url_retorno'] !== '' ? $mp['url_retorno'] . '?r=ok' : api_base(),
        /* 'pending' é o que devolve init_point. Com 'authorized' eles
           esperariam o card_token_id, que só existe com formulário de
           cartão em página nossa. */
        'status'             => 'pending',
        'auto_recurring'     => [
            'frequency'          => $rec[0],
            'frequency_type'     => $rec[1],
            'transaction_amount' => $centavos / 100,
            'currency_id'        => 'BRL',
        ],
        /* Assinatura NÃO aceita a configuração de webhook do painel "Suas
           integrações" — a documentação deles manda configurar na criação.
           Sem esta linha, a renovação do mês que vem não avisa ninguém. */
        'notification_url'   => api_base() . '/webhook-mercadopago.php',
    ];

    /* Os dias grátis viram a data da primeira cobrança. O end_date vai junto
       porque O start_date SOZINHO É IGNORADO — está escrito na referência
       deles, e sem o par a primeira cobrança sairia hoje, cheia, justamente
       pra quem tinha desconto. A data de fim é longe: assinatura acaba
       quando alguém cancela, não numa data marcada. */
    if ($gratis > 0) {
        $corpo['auto_recurring']['start_date'] = gmdate('Y-m-d\TH:i:s.000\Z', time() + $gratis * 86400);
        $corpo['auto_recurring']['end_date']   = gmdate('Y-m-d\TH:i:s.000\Z', strtotime('+10 years'));
    }

    [$http, $r] = mp_http('POST', '/preapproval', $corpo);
    if ($http !== 201 && $http !== 200) {
        error_log('[zc] Mercado Pago recusou a assinatura (' . $http . '): '
            . (is_array($r) ? json_encode($r, JSON_UNESCAPED_UNICODE) : 'resposta vazia'));
        throw new RuntimeException('O Mercado Pago recusou a assinatura agora. Tente de novo em alguns minutos.');
    }

    $id = (string) ($r['id'] ?? '');

    db()->prepare(
        'INSERT INTO assinaturas
             (usuario_id, plano_id, provedor, provedor_id, assinatura_externa, referencia,
              status, renova, teste, cupom, dias_raid)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
    )->execute([
        $usuario_id, (int) $plano['id'], 'mercadopago', $id, $id, $ref,
        'pendente', $mp['modo'] === 'teste' ? 1 : 0,
        $cupom !== '' ? $cupom : null,
        $diasRaid,
    ]);

    return [
        'url'        => (string) ($r['init_point'] ?? ''),
        'referencia' => $ref,
        'assinatura' => $id,
        'cupom'      => $cupom,
        'centavos'   => $centavos,
        'gratis'     => $gratis,
    ];
}

/** O que o Mercado Pago diz sobre uma assinatura. */
function mp_ler_assinatura(string $id): ?array
{
    [$http, $r] = mp_http('GET', '/preapproval/' . rawurlencode($id));
    return ($http === 200 && is_array($r)) ? $r : null;
}

/** O que o Mercado Pago diz sobre UMA cobrança mensal da assinatura. */
function mp_ler_fatura(string $id): ?array
{
    [$http, $r] = mp_http('GET', '/authorized_payments/' . rawurlencode($id));
    return ($http === 200 && is_array($r)) ? $r : null;
}

/**
 * Anota o que mudou numa assinatura. NÃO tira acesso.
 *
 * Cancelar é parar de cobrar, e não tomar de volta o mês que já foi pago.
 * Quem cancela no dia 3 fica com o Pro até o dia 30 — é o que toda
 * assinatura faz, e tirar na hora seria vender trinta dias e entregar três.
 */
function mp_assinatura_estado(string $assinatura_id, string $situacao): void
{
    $renova = $situacao === 'authorized' ? 1 : 0;

    db()->prepare(
        'UPDATE assinaturas
            SET renova = ?,
                cancelada_em = CASE WHEN ? = 0 AND cancelada_em IS NULL THEN NOW()
                                    ELSE cancelada_em END
          WHERE assinatura_externa = ?'
    )->execute([$renova, $renova, $assinatura_id]);
}

/**
 * O Pro dos DIAS GRÁTIS, entregue quando a assinatura é autorizada.
 *
 * Sem isto o desconto viraria castigo. Os dias grátis adiam a primeira
 * cobrança, e quem entrou com 5 dias de raid só seria cobrado dali a cinco
 * dias — mas também só teria Pro dali a cinco dias, porque quem libera o
 * acesso é o pagamento. A pessoa ganharia desconto e ficaria sem o produto
 * justamente no período que ela ganhou.
 *
 * Então o acesso começa aqui, valendo até a data da primeira cobrança. Dali
 * em diante cada cobrança aprovada estende a partir do que já existe, que é
 * como mp_liberar() sempre trabalhou.
 *
 * Roda uma vez: a linha só está 'pendente' antes da primeira cobrança.
 */
function mp_assinatura_comeco(string $assinatura_id, string $primeira_cobranca): void
{
    $st = db()->prepare(
        "SELECT id, usuario_id, dias_raid FROM assinaturas
          WHERE assinatura_externa = ? AND status = 'pendente' LIMIT 1"
    );
    $st->execute([$assinatura_id]);
    $linha = $st->fetch(PDO::FETCH_ASSOC);
    if (!$linha) return;

    $ate = strtotime($primeira_cobranca);
    /* Sem data de cobrança futura não há período grátis nenhum: a cobrança
       sai agora e é ela que vai liberar. Nada a fazer aqui. */
    if (!$ate || $ate <= time()) return;

    db()->prepare("UPDATE assinaturas SET status = 'ativa', valido_ate = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s', $ate), (int) $linha['id']]);

    /* Os dias de raid foram entregues AGORA, então saem do saldo agora. Não
       podem sair de novo na primeira cobrança — e não saem: mp_liberar() só
       gasta quando a linha ainda não está 'ativa', e acabou de ficar. */
    try {
        $gastar = (int) $linha['dias_raid'];
        if ($gastar > 0) {
            db()->prepare('UPDATE raid_saldo SET dias = GREATEST(0, dias - ?) WHERE usuario_id = ?')
                ->execute([$gastar, (int) $linha['usuario_id']]);
        }
    } catch (Throwable $e) { /* sem o SQL 063: não havia desconto mesmo */ }
}

/**
 * Desligar a renovação, a pedido de quem assinou.
 *
 * Tem que existir e tem que ser fácil de achar: cobrança que se renova
 * sozinha e não se desliga sozinha é o que faz alguém pedir estorno pelo
 * banco — e estorno tira o acesso, custa taxa e ainda queima a reputação
 * da conta no Mercado Pago.
 */
function mp_cancelar_assinatura(int $usuario_id): array
{
    $st = db()->prepare(
        "SELECT assinatura_externa FROM assinaturas
          WHERE usuario_id = ? AND provedor = 'mercadopago'
            AND assinatura_externa IS NOT NULL AND renova = 1
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$usuario_id]);
    $id = (string) $st->fetchColumn();

    if ($id === '') return ['ok' => false, 'erro' => 'Você não tem assinatura que se renove.'];

    [$http, $r] = mp_http('PUT', '/preapproval/' . rawurlencode($id), ['status' => 'cancelled']);
    if ($http !== 200) {
        error_log('[zc] Mercado Pago recusou o cancelamento (' . $http . '): '
            . (is_array($r) ? json_encode($r, JSON_UNESCAPED_UNICODE) : ''));
        return ['ok' => false, 'erro' => 'O Mercado Pago não aceitou o cancelamento agora. Tente de novo em alguns minutos.'];
    }

    mp_assinatura_estado($id, 'cancelled');

    /* Até quando o que já foi pago vale. A tela precisa disto pra pessoa não
       achar que perdeu o mês no instante em que clicou. */
    $ate = db()->prepare(
        "SELECT MAX(valido_ate) FROM assinaturas WHERE usuario_id = ? AND status = 'ativa'"
    );
    $ate->execute([$usuario_id]);

    return ['ok' => true, 'ate' => $ate->fetchColumn() ?: null];
}

/** O que o Mercado Pago diz sobre um pagamento. Esta é a única verdade. */
function mp_ler_pagamento(string $pagamento_id): ?array
{
    [$http, $r] = mp_http('GET', '/v1/payments/' . rawurlencode($pagamento_id));
    return ($http === 200 && is_array($r)) ? $r : null;
}

/**
 * Empurra a validade pra frente.
 *
 * A partir do que for maior entre AGORA e a validade que a pessoa já tem —
 * senão quem renova antes de vencer perderia os dias que ainda faltavam,
 * e ser punido por pagar adiantado é o tipo de coisa que gera reembolso.
 */
function mp_liberar(int $usuario_id, array $plano, string $referencia, string $pagamento_id, int $centavos): void
{
    /* UM PAGAMENTO LIBERA UMA VEZ SÓ.
     *
     * A trava que existia era na tabela de avisos, com UNIQUE no
     * x-request-id. Só que o Mercado Pago manda VÁRIOS avisos sobre o mesmo
     * pagamento — um quando entra pendente, outro quando aprova, e reenvios
     * quando acha que a gente não respondeu — e cada um vem com request-id
     * diferente. Todo aviso com status 'approved' passava pela trava e caía
     * aqui, e como esta função soma dias a partir da validade atual, o
     * segundo aviso dava mais trinta dias de graça.
     *
     * A trava certa é pelo id do PAGAMENTO, que é único de verdade. Quando
     * este pagamento já liberou, a linha dele está 'ativa' com o id dele em
     * provedor_id — e aí não há nada a fazer.
     */
    $ja = db()->prepare(
        "SELECT 1 FROM assinaturas
          WHERE provedor = 'mercadopago' AND provedor_id = ? AND status = 'ativa' LIMIT 1"
    );
    $ja->execute([$pagamento_id]);
    if ($ja->fetchColumn()) return;

    /* A PRIMEIRA COBRANÇA DESTA LINHA, OU UMA RENOVAÇÃO?

       Assinatura reusa a MESMA linha todo mês: a referência é a mesma, e só
       o id do pagamento muda. Tudo que é "de uma venda" — gastar os dias de
       raid, pagar comissão de cupom — precisa saber disso, ou aconteceria
       de novo todo mês: o saldo de raid seria descontado doze vezes por um
       desconto que só foi dado uma. */
    $antes = db()->prepare(
        'SELECT id, status, dias_raid, cupom FROM assinaturas
          WHERE referencia = ? AND usuario_id = ? LIMIT 1'
    );
    $antes->execute([$referencia, $usuario_id]);
    $linhaAntes = $antes->fetch(PDO::FETCH_ASSOC) ?: null;
    $primeira = !$linhaAntes || (string) $linhaAntes['status'] !== 'ativa';

    /* OS DIAS DE RAID SAEM DO SALDO AGORA, E SÓ AGORA.

       Este ponto roda uma vez por venda — a trava do pagamento acima e o
       $primeira aqui garantem. O UPDATE tira no máximo o que existe, então
       um saldo que encolheu entre o checkout e a aprovação não vira saldo
       negativo. */
    if ($primeira) {
        try {
            $gastar = (int) ($linhaAntes['dias_raid'] ?? 0);
            if ($gastar > 0) {
                db()->prepare('UPDATE raid_saldo SET dias = GREATEST(0, dias - ?) WHERE usuario_id = ?')
                    ->execute([$gastar, $usuario_id]);
            }
        } catch (Throwable $e) { /* sem o SQL 063: não havia desconto mesmo */ }
    }

    if (($plano['periodo'] ?? '') === 'vitalicio') {
        $ate = MP_VITALICIO_ATE;
    } else {
        $dias = MP_DIAS[$plano['periodo']] ?? 30;

        $st = db()->prepare(
            "SELECT MAX(valido_ate) FROM assinaturas
              WHERE usuario_id = ? AND status = 'ativa' AND valido_ate IS NOT NULL"
        );
        $st->execute([$usuario_id]);
        $atual = $st->fetchColumn();

        $base = ($atual && strtotime($atual) > time()) ? strtotime($atual) : time();
        $ate = date('Y-m-d H:i:s', $base + $dias * 86400);
    }

    /* A linha certa é a que o checkout criou, achada pela referência. Se ela
       sumiu (banco limpo, teste antigo), cria uma: pagamento confirmado não
       pode ficar sem acesso por causa de linha perdida. */
    $up = db()->prepare(
        "UPDATE assinaturas
            SET status = 'ativa', valido_ate = ?, provedor_id = ?, pago_centavos = ?
          WHERE referencia = ? AND usuario_id = ?"
    );
    $up->execute([$ate, $pagamento_id, $centavos, $referencia, $usuario_id]);

    /* Achar a linha DEPOIS de atualizar, e pelo mesmo critério: é dela que
       sai o id que amarra a comissão à venda. */
    $ln = db()->prepare('SELECT id, cupom FROM assinaturas WHERE referencia = ? AND usuario_id = ? LIMIT 1');
    $ln->execute([$referencia, $usuario_id]);
    $linha = $ln->fetch();

    /* A comissão é pela VENDA. Numa assinatura o cupom fica gravado na linha
       pra sempre, e sem o $primeira o parceiro receberia de novo a cada
       renovação, por uma indicação que ele fez uma vez só. */
    if ($primeira && $linha && !empty($linha['cupom'])) {
        cupom_registra_venda((string) $linha['cupom'], (int) $linha['id'], $centavos);
    }

    if (!$up->rowCount()) {
        db()->prepare(
            "INSERT INTO assinaturas
                 (usuario_id, plano_id, provedor, provedor_id, referencia, status, valido_ate, pago_centavos)
             VALUES (?, ?, 'mercadopago', ?, ?, 'ativa', ?, ?)
             ON DUPLICATE KEY UPDATE status = 'ativa', valido_ate = VALUES(valido_ate)"
        )->execute([$usuario_id, (int) $plano['id'], $pagamento_id, $referencia, $ate, $centavos]);
    }
}

/**
 * O que é, de verdade, a credencial que está no config.
 *
 * O 403 "At least one policy returned UNAUTHORIZED" não diz nada sobre a
 * causa, e as causas são todas parecidas de fora: chave pública no lugar do
 * access token, credencial de um app e segredo de outro, credencial de
 * usuário de teste onde deveria ir a da aplicação. Perguntar quem é o dono
 * do token separa as três em um pedido só.
 *
 * NUNCA devolve o token. Só o formato dele e o que o Mercado Pago responde.
 */
function mp_diagnostico(): array
{
    $mp = mp_cfg();
    $tk = $mp['access_token'];

    /* A chave pública e o access token dos dois começam com TEST- ou
       APP_USR-, e é por isso que trocar um pelo outro é tão fácil. O que
       separa: o access token tem partes separadas por hífen e é bem mais
       longo. */
    $forma = 'desconhecido';
    if ($tk === '') {
        $forma = 'vazio';
    } elseif (preg_match('/^(TEST|APP_USR)-\d{10,}-\d{6}-[0-9a-f]{32}-\d+$/', $tk)) {
        $forma = 'access token';
    } elseif (preg_match('/^(TEST|APP_USR)-[0-9a-f-]{30,40}$/', $tk)) {
        $forma = 'CHAVE PÚBLICA (public key) — não serve aqui';
    }

    $r = [
        'modo'            => $mp['modo'],
        'ligado'          => $mp['ligado'],
        'token_prefixo'   => $tk === '' ? '' : explode('-', $tk)[0],
        'token_tamanho'   => strlen($tk),
        'token_forma'     => $forma,
        'tem_segredo'     => $mp['webhook_secret'] !== '',
        'notification_url'=> api_base() . '/webhook-mercadopago.php',
    ];

    /* De quem é o token. Este endereço aceita qualquer access token válido,
       então é o teste mais barato de "a credencial presta". */
    try {
        [$http, $eu] = mp_http('GET', '/users/me');
        $r['users_me_http'] = $http;
        if ($http === 200 && is_array($eu)) {
            $r['conta'] = [
                'id'       => $eu['id'] ?? null,
                'apelido'  => $eu['nickname'] ?? null,
                'site'     => $eu['site_id'] ?? null,
                'tipo'     => $eu['user_type'] ?? null,
            ];
        } else {
            $r['users_me_erro'] = is_array($eu) ? ($eu['message'] ?? json_encode($eu)) : 'sem corpo';
        }
    } catch (Throwable $e) {
        $r['users_me_erro'] = $e->getMessage();
    }

    /* E a prova final: tenta criar uma cobrança de um centavo e joga fora.
       É o mesmo caminho do checkout de verdade, então o erro que aparecer
       aqui é exatamente o erro que a pessoa levaria. */
    try {
        [$http, $p] = mp_http('POST', '/checkout/preferences', [
            'items' => [[
                'title' => 'teste de credencial', 'quantity' => 1,
                'currency_id' => 'BRL', 'unit_price' => 1.0,
            ]],
        ]);
        $r['preferencia_http'] = $http;
        if ($http === 201 || $http === 200) {
            $r['preferencia'] = 'criou — a credencial serve pra cobrar';
        } else {
            $r['preferencia_erro'] = is_array($p) ? ($p['message'] ?? json_encode($p)) : 'sem corpo';
            $r['preferencia_causa'] = is_array($p) ? ($p['cause'] ?? null) : null;
        }
    } catch (Throwable $e) {
        $r['preferencia_erro'] = $e->getMessage();
    }

    return $r;
}

/**
 * Tira o acesso de um pagamento que voltou atrás.
 *
 * ISTO NÃO É DETALHE. Sem tratar estorno e contestação, quem pagou, foi
 * liberado e depois pediu o dinheiro de volta continua com o Pro pra sempre
 * — e ainda por cima com o dinheiro. É o buraco que qualquer um encontra
 * sozinho na segunda vez.
 *
 * A validade volta pro que era ANTES deste pagamento, e não pra hoje: quem
 * tinha trinta dias comprados antes e estornou o pagamento seguinte não
 * pode perder os trinta que já eram dele.
 */
function mp_estornar(string $referencia, string $situacao): bool
{
    $st = db()->prepare(
        'SELECT id, usuario_id, plano_id, valido_ate FROM assinaturas WHERE referencia = ? LIMIT 1'
    );
    $st->execute([$referencia]);
    $a = $st->fetch();
    if (!$a || $a['valido_ate'] === null) return false;

    /* OS DIAS GANHOS RAIDANDO VOLTAM PRO SALDO.

       Sem isto, quem usou dias como desconto e teve o pagamento estornado
       perdia as duas coisas: o acesso E os dias, que ele levou semanas
       raidando pra juntar. O dinheiro voltou pra ele, então o desconto
       não foi usado — e o que paga o desconto tem que voltar também.

       O zerar do dias_raid é o que impede devolver duas vezes: um segundo
       aviso de estorno do mesmo pagamento encontra zero e não soma nada. */
    try {
        $dr = db()->prepare('SELECT dias_raid FROM assinaturas WHERE id = ?');
        $dr->execute([(int) $a['id']]);
        $volta_dias = (int) $dr->fetchColumn();

        if ($volta_dias > 0) {
            db()->prepare('INSERT INTO raid_saldo (usuario_id, dias) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE dias = dias + VALUES(dias)')
                ->execute([(int) $a['usuario_id'], $volta_dias]);
            db()->prepare('UPDATE assinaturas SET dias_raid = 0 WHERE id = ?')
                ->execute([(int) $a['id']]);
        }
    } catch (Throwable $e) { /* sem o SQL 063: não houve desconto pra devolver */ }

    $plano = mp_plano_por_id((int) $a['plano_id']);
    $periodo = $plano['periodo'] ?? 'mensal';

    if ($periodo === 'vitalicio') {
        /* Vitalício estornado não tem "voltar um pouco": ou vale, ou não
           vale. */
        $volta = null;
    } else {
        $dias = MP_DIAS[$periodo] ?? 30;
        $volta = date('Y-m-d H:i:s', strtotime((string) $a['valido_ate']) - $dias * 86400);
        /* Se o que sobra já passou, não há mais acesso a devolver. */
        if (strtotime($volta) <= time()) $volta = null;
    }

    db()->prepare(
        "UPDATE assinaturas SET status = 'cancelada', valido_ate = ? WHERE id = ?"
    )->execute([$volta, (int) $a['id']]);

    /* Dinheiro que voltou não gera comissão. Marca em vez de apagar: o
       parceiro pode já ter visto essa venda, e sumir com a linha é pior do
       que mostrá-la estornada. */
    cupom_estorna_venda((int) $a['id']);

    return true;
}

/**
 * Procura um pagamento aprovado que ficou sem liberar.
 *
 * Webhook é entrega "na melhor das intenções": ele se perde, chega fora de
 * ordem, ou bate num servidor que estava fora do ar. Sem uma forma de a
 * própria pessoa reconciliar, cada aviso perdido vira uma conversa no
 * privado — e a pessoa já pagou, então a conversa começa errada.
 *
 * Devolve quantas linhas foram liberadas.
 */
function mp_reconciliar(int $usuario_id): array
{
    $st = db()->prepare(
        "SELECT referencia, plano_id, assinatura_externa FROM assinaturas
          WHERE usuario_id = ? AND status = 'pendente'
            AND criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $st->execute([$usuario_id]);

    $liberados = 0;
    $vistos = 0;

    foreach ($st->fetchAll() as $linha) {
        $ref = (string) $linha['referencia'];
        if ($ref === '') continue;
        $vistos++;

        /* Pergunta ao Mercado Pago o que existe com esta referência. É a
           mesma verdade que o webhook usaria, só que puxada por nós. */
        [$http, $r] = mp_http('GET', '/v1/payments/search?external_reference=' . rawurlencode($ref));

        /* ASSINATURA NÃO APARECE NA BUSCA DE PAGAMENTOS QUANDO AINDA NÃO FOI
           COBRADA — e ela pode estar autorizada e válida assim mesmo, porque
           os dias grátis empurram a primeira cobrança pra frente.

           Sem este ramo, quem assinou com desconto e clicou em "já paguei e
           não liberou" ouviria que não existe cobrança nenhuma — sendo que a
           assinatura está de pé do outro lado. */
        if ($http !== 200 || empty($r['results'])) {
            $ass = (string) ($linha['assinatura_externa'] ?? '');
            if ($ass !== '') {
                $as = mp_ler_assinatura($ass);
                if ($as && (string) ($as['status'] ?? '') === 'authorized') {
                    mp_assinatura_estado($ass, 'authorized');
                    mp_assinatura_comeco($ass, (string) ($as['next_payment_date'] ?? ''));
                    $liberados++;
                }
            }
            continue;
        }

        foreach ($r['results'] as $pag) {
            if (($pag['status'] ?? '') !== 'approved') continue;

            $plano = mp_plano_por_id((int) $linha['plano_id']);
            if (!$plano) continue;

            $centavos = (int) round(((float) ($pag['transaction_amount'] ?? 0)) * 100);
            mp_liberar($usuario_id, $plano, $ref, (string) ($pag['id'] ?? ''), $centavos);
            $liberados++;
            break;
        }
    }

    return ['pendentes' => $vistos, 'liberados' => $liberados];
}

function mp_plano_por_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM planos WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $p = $st->fetch();
    return $p ?: null;
}

function mp_plano_por_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM planos WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $p = $st->fetch();
    return $p ?: null;
}
