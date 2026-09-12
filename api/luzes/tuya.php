<?php
/**
 * Tuya — a nuvem por trás de meia dúzia de marcas.
 *
 * Contrato em _MODELO.php.
 *
 * Positivo Casa Inteligente, Avant Neo, Smart Life, Elgin e boa parte das
 * lâmpadas baratas de mercado livre são Tuya por dentro. Não existe API
 * "da Positivo": existe a da Tuya, e ela atende todas.
 *
 * O PORÉM, E ELE É GRANDE: a Tuya não dá acesso a quem só tem o aplicativo.
 * A pessoa precisa criar um projeto de graça em iot.tuya.com, ligar a conta
 * do aplicativo nele e copiar duas chaves. O texto de 'ajuda' explica o
 * caminho, e a tela mostra o texto — mas isso é muito mais trabalho do que
 * conectar a LIFX, e não tem como ser menos: é regra deles.
 *
 * A assinatura de cada chamada é o pedaço chato. Ela é
 *   HMAC-SHA256(client_id + [access_token] + t + método + "\n" +
 *               sha256(corpo) + "\n\n" + caminho, segredo)
 * em maiúsculas. Um espaço a mais em qualquer pedaço e vem "sign invalid".
 */

const TUYA_MAX = 8;

/* Os centros de dados deles. O Brasil cai no da América. */
const TUYA_REGIOES = [
    'us' => 'https://openapi.tuyaus.com',
    'eu' => 'https://openapi.tuyaeu.com',
    'in' => 'https://openapi.tuyain.com',
    'cn' => 'https://openapi.tuyacn.com',
];

function tuya_base(array $cfg): string
{
    $r = strtolower(trim((string) ($cfg['regiao'] ?? 'us')));
    return TUYA_REGIOES[$r] ?? TUYA_REGIOES['us'];
}

/**
 * Uma chamada assinada.
 *
 * $token vazio é o pedido do próprio token — a Tuya assina esse sem ele.
 */
function tuya_chama(array $cfg, string $metodo, string $caminho, ?array $corpo = null, string $token = ''): array
{
    $id      = (string) ($cfg['id'] ?? '');
    $segredo = (string) ($cfg['segredo'] ?? '');
    $ms      = (string) round(microtime(true) * 1000);
    $json    = $corpo === null ? '' : json_encode($corpo, JSON_UNESCAPED_UNICODE);

    $assinar = strtoupper($metodo) . "\n" . hash('sha256', $json) . "\n\n" . $caminho;
    $sign    = strtoupper(hash_hmac('sha256', $id . $token . $ms . $assinar, $segredo));

    $cabecalhos = [
        'client_id: ' . $id,
        'sign: ' . $sign,
        't: ' . $ms,
        'sign_method: HMAC-SHA256',
        'Content-Type: application/json',
    ];
    if ($token !== '') $cabecalhos[] = 'access_token: ' . $token;

    return luz_http($metodo, tuya_base($cfg) . $caminho, $cabecalhos, $corpo === null ? null : $json);
}

/** O token de acesso, que vale duas horas e é pedido a cada uso. */
function tuya_token(array $cfg): string
{
    [$http, $r] = tuya_chama($cfg, 'GET', '/v1.0/token?grant_type=1');
    if ($http !== 200 || empty($r['success'])) return '';
    return (string) ($r['result']['access_token'] ?? '');
}

/* As lâmpadas Tuya falam por "códigos". As novas usam o sufixo _v2, com
   escala de 0 a 1000; as antigas não têm sufixo e vão até 255. Não dá pra
   saber de fora qual é qual, então manda-se o par novo e, se a Tuya
   recusar, o velho. Duas chamadas só quando a primeira falha. */
const TUYA_CODIGOS = [
    ['cor' => 'colour_data_v2', 'brilho' => 'bright_value_v2', 'teto' => 1000],
    ['cor' => 'colour_data',    'brilho' => 'bright_value',    'teto' => 255],
];

/** #RRGGBB em matiz, saturação e valor na escala que a Tuya usa. */
function tuya_hsv(string $hex, int $teto): array
{
    [$r, $g, $b] = luz_rgb($hex);
    $r /= 255; $g /= 255; $b /= 255;

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $dif = $max - $min;

    $h = 0.0;
    if ($dif > 0) {
        if ($max === $r)      $h = 60 * fmod(($g - $b) / $dif, 6);
        elseif ($max === $g)  $h = 60 * ((($b - $r) / $dif) + 2);
        else                  $h = 60 * ((($r - $g) / $dif) + 4);
    }
    if ($h < 0) $h += 360;

    return [
        'h' => (int) round($h),
        's' => (int) round(($max > 0 ? $dif / $max : 0) * $teto),
        'v' => (int) round($max * $teto),
    ];
}

return [
    'id'    => 'tuya',
    'nome'  => 'Tuya (Positivo, Avant Neo, Smart Life)',
    'ajuda' => 'Serve pra qualquer lâmpada que funcione no Smart Life, no Positivo Casa Inteligente '
             . 'ou no Avant Neo. Dá trabalho uma vez: entre em iot.tuya.com, crie uma conta de '
             . 'desenvolvedor (é de graça), crie um Cloud Project, e na aba "Devices" use '
             . '"Link App Account" pra ligar a conta do seu aplicativo lendo o QR code. '
             . 'O Access ID e o Access Secret ficam na aba "Overview" do projeto.',
    'nuvem' => true,

    'campos' => [
        ['chave' => 'id',      'rotulo' => 'Access ID',     'segredo' => false],
        ['chave' => 'segredo', 'rotulo' => 'Access Secret', 'segredo' => true],
        ['chave' => 'regiao',  'rotulo' => 'Centro de dados (us, eu, in ou cn)', 'segredo' => false],
    ],

    'testar' => function (array $cfg): array {
        if (empty($cfg['id']) || empty($cfg['segredo'])) {
            return ['ok' => false, 'erro' => 'Preencha o Access ID e o Access Secret.'];
        }

        $token = tuya_token($cfg);
        if ($token === '') {
            return ['ok' => false, 'erro' => 'A Tuya recusou essas chaves. Confira se copiou do projeto certo '
                                           . 'e se o centro de dados é o mesmo onde o projeto foi criado.'];
        }

        [$http, $r] = tuya_chama($cfg, 'GET', '/v1.0/iot-01/associated-users/devices?size=50', null, $token);
        if ($http !== 200 || empty($r['success'])) {
            return ['ok' => false, 'erro' => 'As chaves valem, mas a Tuya não devolveu a lista de aparelhos. '
                                           . 'Confira se você ligou a conta do aplicativo no projeto.'];
        }

        $aps = [];
        foreach (($r['result']['devices'] ?? []) as $dev) {
            if (empty($dev['id'])) continue;

            /* Só o que sabe mudar de cor: tomada e interruptor Tuya
               aparecem na mesma lista e não servem pra nada aqui. */
            $temCor = false;
            foreach (($dev['status'] ?? []) as $s) {
                $c = (string) ($s['code'] ?? '');
                if ($c === 'colour_data' || $c === 'colour_data_v2') $temCor = true;
            }
            if (!$temCor) continue;

            $aps[] = ['id' => (string) $dev['id'], 'nome' => (string) ($dev['name'] ?? $dev['id'])];
        }
        if (!$aps) {
            return ['ok' => false, 'erro' => 'Conectou, mas não achei nenhuma luz colorida nessa conta. '
                                           . 'Lâmpada só de branco e tomada inteligente não servem aqui.'];
        }

        return ['ok' => true, 'aparelhos' => $aps];
    },

    'aplicar' => function (array $cfg, array $ordem, array $aparelhos): array {
        if (!$aparelhos) return ['ok' => false, 'erro' => 'Escolha quais luzes Tuya o chat controla.'];

        $token = tuya_token($cfg);
        if ($token === '') return ['ok' => false, 'erro' => 'A Tuya não aceitou as chaves agora.'];

        $feitos = 0;
        foreach (array_slice($aparelhos, 0, TUYA_MAX) as $id) {
            $caminho = '/v1.0/devices/' . rawurlencode((string) $id) . '/commands';

            if ($ordem['acao'] === 'desligar') {
                [$http, $r] = tuya_chama($cfg, 'POST', $caminho,
                    ['commands' => [['code' => 'switch_led', 'value' => false]]], $token);
                if ($http === 200 && !empty($r['success'])) $feitos++;
                continue;
            }

            /* A Tuya recusa o lote inteiro quando não conhece UM código, e
               é por isso que os dois jogos são tentados em ordem. */
            foreach (TUYA_CODIGOS as $cod) {
                $comandos = [['code' => 'switch_led', 'value' => true]];

                if ($ordem['cor']) {
                    $comandos[] = ['code' => 'work_mode', 'value' => 'colour'];
                    $comandos[] = ['code' => $cod['cor'], 'value' => tuya_hsv((string) $ordem['cor'], $cod['teto'])];
                }
                if ($ordem['brilho'] !== null) {
                    $b = max(1, min(100, (int) $ordem['brilho']));
                    $comandos[] = ['code' => $cod['brilho'],
                                   'value' => (int) round($cod['teto'] * $b / 100)];
                }

                [$http, $r] = tuya_chama($cfg, 'POST', $caminho, ['commands' => $comandos], $token);
                if ($http === 200 && !empty($r['success'])) { $feitos++; break; }
            }
        }

        /* A Tuya não tem "volta depois": o que existe é mandar de novo. */
        return $feitos ? ['ok' => true] : ['ok' => false, 'erro' => 'a Tuya não obedeceu'];
    },
];
