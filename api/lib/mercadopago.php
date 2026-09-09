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

const MP_API = 'https://api.mercadopago.com';

/** Quantos dias cada período vale. */
const MP_DIAS = ['mensal' => 30, 'anual' => 365];

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
function mp_criar_cobranca(int $usuario_id, array $plano): array
{
    $mp = mp_cfg();
    $ref = mp_referencia($usuario_id, (string) $plano['slug']);
    $valor = ((int) $plano['preco_centavos']) / 100;

    $corpo = [
        'items' => [[
            'id'          => (string) $plano['slug'],
            'title'       => 'ZocaController — ' . $plano['nome'],
            'quantity'    => 1,
            'currency_id' => 'BRL',
            'unit_price'  => $valor,
        ]],
        'external_reference' => $ref,
        'notification_url'   => rtrim(cfg()['api_base'] ?? '', '/') . '/webhook-mercadopago.php',
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
        $msg = is_array($r) ? ($r['message'] ?? json_encode($r)) : 'resposta vazia';
        throw new RuntimeException('O Mercado Pago recusou a cobrança (' . $http . '): ' . $msg);
    }

    db()->prepare(
        'INSERT INTO assinaturas (usuario_id, plano_id, provedor, provedor_id, referencia, status, teste)
              VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $usuario_id, (int) $plano['id'], 'mercadopago',
        (string) $r['id'], $ref, 'pendente', $mp['modo'] === 'teste' ? 1 : 0,
    ]);

    /* Em modo de teste o link é o sandbox_init_point: o mesmo checkout, mas
       que só aceita os cartões de teste e não move dinheiro nenhum. */
    $url = ($mp['modo'] === 'teste' && !empty($r['sandbox_init_point']))
        ? $r['sandbox_init_point']
        : ($r['init_point'] ?? '');

    return ['url' => $url, 'referencia' => $ref, 'preferencia' => (string) $r['id']];
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
    $dias = MP_DIAS[$plano['periodo']] ?? 30;

    $st = db()->prepare(
        "SELECT MAX(valido_ate) FROM assinaturas
          WHERE usuario_id = ? AND status = 'ativa' AND valido_ate IS NOT NULL"
    );
    $st->execute([$usuario_id]);
    $atual = $st->fetchColumn();

    $base = ($atual && strtotime($atual) > time()) ? strtotime($atual) : time();
    $ate = date('Y-m-d H:i:s', $base + $dias * 86400);

    /* A linha certa é a que o checkout criou, achada pela referência. Se ela
       sumiu (banco limpo, teste antigo), cria uma: pagamento confirmado não
       pode ficar sem acesso por causa de linha perdida. */
    $up = db()->prepare(
        "UPDATE assinaturas
            SET status = 'ativa', valido_ate = ?, provedor_id = ?, pago_centavos = ?
          WHERE referencia = ? AND usuario_id = ?"
    );
    $up->execute([$ate, $pagamento_id, $centavos, $referencia, $usuario_id]);

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
        'notification_url'=> rtrim(cfg()['api_base'] ?? '', '/') . '/webhook-mercadopago.php',
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
