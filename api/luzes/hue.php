<?php
/**
 * Philips Hue — pela nuvem oficial (api.meethue.com).
 *
 * Contrato em _MODELO.php, mais o bloco 'oauth' que este arquivo estreia.
 *
 * A Hue não tem token pra colar: a pessoa entra na conta dela no site da
 * Philips e autoriza. Por isso aqui não existe campo nenhum — existe um
 * botão, e este arquivo diz pra onde ele leva e o que fazer na volta.
 *
 * Três coisas acontecem na volta, nesta ordem, e todas uma vez só:
 *   1. o código vira acesso (vale uns 7 dias) e renovação (uns 100);
 *   2. o "botão da ponte" é apertado por software, porque sem isso a ponte
 *      não deixa ninguém novo entrar;
 *   3. nasce uma chave de aplicativo, que é o crachá das ordens seguintes.
 *
 * O acesso vence, e quem renova é o núcleo: ele chama 'renovar' sozinho
 * quando a hora está perto, com o que este arquivo guardou.
 *
 * Pra isto funcionar, o dono do site precisa cadastrar um app em
 * developers.meethue.com e pôr no config.php:
 *
 *   'philips_hue' => ['client_id' => '', 'client_secret' => '', 'app_id' => ''],
 *
 * O Callback URL cadastrado lá tem que ser, letra por letra,
 * https://api.zocahop.com/luzes.php
 */

const HUE_BASE  = 'https://api.meethue.com';
const HUE_ROTA  = 'https://api.meethue.com/route/clip/v2/resource';
const HUE_MAX   = 12;

function hue_app(): array
{
    $c = cfg()['philips_hue'] ?? [];
    if (empty($c['client_id']) || empty($c['client_secret'])) {
        throw new RuntimeException('A Philips Hue ainda não está configurada neste servidor.');
    }
    return $c;
}

function hue_cabecalho(array $cfg): array
{
    return [
        'Authorization: Bearer ' . (string) ($cfg['acesso'] ?? ''),
        'hue-application-key: ' . (string) ($cfg['chave'] ?? ''),
        'Content-Type: application/json',
    ];
}

/** A resposta do portal vira a config que fica guardada. */
function hue_tokens(array $t, array $antes = []): array
{
    return array_merge($antes, [
        'acesso'   => (string) ($t['access_token'] ?? ''),
        'renovo'   => (string) ($t['refresh_token'] ?? ($antes['renovo'] ?? '')),
        'expira'   => time() + max(300, (int) ($t['expires_in'] ?? 604800)),
    ]);
}

/**
 * RGB pro jeito da Hue, que é o diagrama de cores da CIE.
 *
 * A conta é a que a própria Philips publica: tira o gamma do sRGB, passa
 * pela matriz do Wide Gamut RGB e normaliza. Mandar R, G e B crus daria
 * cor errada — a lâmpada não mistura tinta como a tela mistura luz.
 */
function hue_xy(string $hex): array
{
    [$r, $g, $b] = luz_rgb($hex);

    $lin = function (float $c): float {
        $c /= 255;
        return $c > 0.04045 ? pow(($c + 0.055) / 1.055, 2.4) : $c / 12.92;
    };
    $r = $lin((float) $r); $g = $lin((float) $g); $b = $lin((float) $b);

    $x = $r * 0.664511 + $g * 0.154324 + $b * 0.162028;
    $y = $r * 0.283881 + $g * 0.668433 + $b * 0.047685;
    $z = $r * 0.000088 + $g * 0.072310 + $b * 0.986039;

    $soma = $x + $y + $z;
    if ($soma <= 0) return [0.3127, 0.3290];      /* branco, pra não dividir por zero */

    return [round($x / $soma, 4), round($y / $soma, 4)];
}

/** Uma ordem pra uma luz. Devolve true se a Hue aceitou. */
function hue_manda(array $cfg, string $id, array $corpo): bool
{
    [$http] = luz_http('PUT', HUE_ROTA . '/light/' . rawurlencode($id), hue_cabecalho($cfg), $corpo);
    return $http >= 200 && $http < 300;
}

return [
    'id'    => 'hue',
    'nome'  => 'Philips Hue',
    'ajuda' => 'Clique em conectar e entre com a sua conta da Philips Hue. '
             . 'A sua ponte (aquela caixinha branca) precisa estar ligada na internet.',
    'nuvem' => true,

    /* Nada pra digitar: quem pergunta é a Philips, na casa dela. */
    'campos' => [],

    'oauth' => [
        'entrar' => function (string $estado): string {
            $a = hue_app();
            return HUE_BASE . '/v2/oauth2/authorize?' . http_build_query([
                'client_id'     => (string) $a['client_id'],
                'response_type' => 'code',
                'state'         => $estado,
                'appid'         => (string) ($a['app_id'] ?? ''),
                'deviceid'      => 'zocacontroller',
                'devicename'    => 'ZocaController',
            ]);
        },

        'voltar' => function (array $query): array {
            $a = hue_app();
            $codigo = (string) ($query['code'] ?? '');
            if ($codigo === '') return ['ok' => false, 'erro' => 'A Philips não mandou o código.'];

            /* O portal da Hue só aceita Digest; Basic ele responde 401. */
            [$http, $t] = luz_http_digest('POST',
                HUE_BASE . '/v2/oauth2/token?' . http_build_query([
                    'code' => $codigo, 'grant_type' => 'authorization_code',
                ]),
                (string) $a['client_id'], (string) $a['client_secret'],
                ['Accept: application/json']);

            if ($http !== 200 || empty($t['access_token'])) {
                return ['ok' => false, 'erro' => 'A Philips recusou a autorização. Tente conectar de novo.'];
            }
            $cfg = hue_tokens($t);

            /* O botão físico da ponte, apertado por software. Sem isto o
               passo seguinte responde "link button not pressed". */
            luz_http('PUT', HUE_BASE . '/bridge/0/config',
                ['Authorization: Bearer ' . $cfg['acesso'], 'Content-Type: application/json'],
                ['linkbutton' => true]);

            [$hc, $r] = luz_http('POST', HUE_BASE . '/bridge',
                ['Authorization: Bearer ' . $cfg['acesso'], 'Content-Type: application/json'],
                ['devicetype' => 'zocacontroller#chat']);

            $chave = (string) ($r[0]['success']['username'] ?? '');
            if ($hc < 200 || $hc >= 300 || $chave === '') {
                return ['ok' => false, 'erro' => 'A conta autorizou, mas a sua ponte Hue não respondeu. '
                                               . 'Confira se ela está ligada na internet e tente de novo.'];
            }
            $cfg['chave'] = $chave;

            return ['ok' => true, 'config' => $cfg];
        },

        'renovar' => function (array $cfg): ?array {
            $a = hue_app();
            if (empty($cfg['renovo'])) return null;

            [$http, $t] = luz_http_digest('POST',
                HUE_BASE . '/v2/oauth2/token?' . http_build_query([
                    'grant_type' => 'refresh_token', 'refresh_token' => (string) $cfg['renovo'],
                ]),
                (string) $a['client_id'], (string) $a['client_secret'],
                ['Accept: application/json']);

            if ($http !== 200 || empty($t['access_token'])) return null;
            return hue_tokens($t, $cfg);
        },
    ],

    'testar' => function (array $cfg): array {
        if (empty($cfg['acesso']) || empty($cfg['chave'])) {
            return ['ok' => false, 'erro' => 'A conta da Philips ainda não está conectada.'];
        }

        [$http, $r] = luz_http('GET', HUE_ROTA . '/light', hue_cabecalho($cfg));

        if ($http === 401 || $http === 403) {
            return ['ok' => false, 'erro' => 'O acesso à Philips venceu. Conecte de novo.'];
        }
        if ($http !== 200) return ['ok' => false, 'erro' => 'A Philips não respondeu agora. Tente de novo.'];

        $aps = [];
        foreach (($r['data'] ?? []) as $l) {
            if (empty($l['id'])) continue;
            $aps[] = [
                'id'   => (string) $l['id'],
                'nome' => (string) ($l['metadata']['name'] ?? 'Luz'),
            ];
        }
        if (!$aps) return ['ok' => false, 'erro' => 'A conta conectou, mas não achei lâmpada nenhuma nessa ponte.'];

        return ['ok' => true, 'aparelhos' => $aps];
    },

    'aplicar' => function (array $cfg, array $ordem, array $aparelhos): array {
        if (!$aparelhos) return ['ok' => false, 'erro' => 'Escolha quais luzes Hue o chat controla.'];

        $feitos = 0;
        foreach (array_slice($aparelhos, 0, HUE_MAX) as $id) {
            if ($ordem['acao'] === 'desligar') {
                if (hue_manda($cfg, (string) $id, ['on' => ['on' => false]])) $feitos++;
                continue;
            }

            /* PISCAR É UM CAMPO DELES, E NÃO DOIS ENVIOS NOSSOS.

               Mandar a cor e depois a cor de antes daria errado: entre uma
               coisa e outra a pessoa pode mexer no aplicativo, e a gente
               reporia uma cor que já não era a dela. O 'breathe' da Hue
               volta sozinho ao que estava. */
            if ($ordem['acao'] === 'piscar' || (int) $ordem['ms'] > 0) {
                $corpo = ['on' => ['on' => true], 'alert' => ['action' => 'breathe']];
                if ($ordem['cor']) {
                    [$x, $y] = hue_xy((string) $ordem['cor']);
                    $corpo['color'] = ['xy' => ['x' => $x, 'y' => $y]];
                }
                if (hue_manda($cfg, (string) $id, $corpo)) $feitos++;
                continue;
            }

            $corpo = ['on' => ['on' => true]];
            if ($ordem['cor']) {
                [$x, $y] = hue_xy((string) $ordem['cor']);
                $corpo['color'] = ['xy' => ['x' => $x, 'y' => $y]];
            }
            if ($ordem['brilho'] !== null) {
                $corpo['dimming'] = ['brightness' => max(1, min(100, (int) $ordem['brilho']))];
            }

            if (hue_manda($cfg, (string) $id, $corpo)) $feitos++;
        }

        return $feitos ? ['ok' => true] : ['ok' => false, 'erro' => 'a Philips não obedeceu'];
    },
];
