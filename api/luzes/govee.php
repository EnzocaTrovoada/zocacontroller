<?php
/**
 * Govee — nuvem oficial (openapi.api.govee.com).
 *
 * Contrato em _MODELO.php.
 *
 * A chave sai do aplicativo da Govee, em Perfil > Configurações > Apply for
 * API Key; ela chega por e-mail.
 *
 * Aqui existe laço por aparelho, ao contrário do que diz a regra geral: a
 * Govee só aceita UM aparelho e UMA capacidade por chamada. Trocar cor e
 * brilho de duas lâmpadas são quatro chamadas. Os limites deles (720 por
 * minuto na conta, 120 por minuto no aparelho) aguentam, mas é por isso que
 * o número de aparelhos é limitado aqui.
 */

const GOVEE_BASE = 'https://openapi.api.govee.com/router/api/v1';
const GOVEE_MAX  = 8;

function govee_cabecalho(array $cfg): array
{
    return ['Govee-API-Key: ' . (string) ($cfg['chave'] ?? ''), 'Content-Type: application/json'];
}

/** O id junta modelo e aparelho porque a Govee exige os dois em toda ordem. */
function govee_parte(string $id): array
{
    $p = explode('|', $id, 2);
    return count($p) === 2 ? $p : ['', $id];
}

function govee_manda(array $cfg, string $sku, string $dev, array $capacidade): bool
{
    [$http, $r] = luz_http('POST', GOVEE_BASE . '/device/control', govee_cabecalho($cfg), [
        'requestId' => bin2hex(random_bytes(8)),
        'payload'   => ['sku' => $sku, 'device' => $dev, 'capability' => $capacidade],
    ]);
    return $http >= 200 && $http < 300 && (int) ($r['code'] ?? 200) === 200;
}

return [
    'id'    => 'govee',
    'nome'  => 'Govee',
    'ajuda' => 'No aplicativo da Govee: Perfil, Configurações, "Apply for API Key". A chave chega por e-mail.',
    'nuvem' => true,

    'campos' => [
        ['chave' => 'chave', 'rotulo' => 'Chave de API', 'segredo' => true],
    ],

    'testar' => function (array $cfg): array {
        [$http, $r] = luz_http('GET', GOVEE_BASE . '/user/devices', govee_cabecalho($cfg));

        if ($http === 401 || $http === 403) return ['ok' => false, 'erro' => 'A Govee recusou essa chave.'];
        if ($http !== 200) return ['ok' => false, 'erro' => 'A Govee não respondeu agora. Tente de novo.'];

        $aps = [];
        foreach (($r['data'] ?? []) as $d) {
            if (empty($d['device']) || empty($d['sku'])) continue;

            /* Só o que sabe mudar de cor: tomada inteligente da Govee
               também aparece nesta lista, e não serve pra nada aqui. */
            $temCor = false;
            foreach (($d['capabilities'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'devices.capabilities.color_setting') $temCor = true;
            }
            if (!$temCor) continue;

            $aps[] = [
                'id'   => $d['sku'] . '|' . $d['device'],
                'nome' => (string) ($d['deviceName'] ?? $d['device']),
            ];
        }
        if (!$aps) return ['ok' => false, 'erro' => 'A chave vale, mas não achei nenhuma luz colorida nessa conta.'];

        return ['ok' => true, 'aparelhos' => $aps];
    },

    'aplicar' => function (array $cfg, array $ordem, array $aparelhos): array {
        if (!$aparelhos) return ['ok' => false, 'erro' => 'Escolha quais luzes Govee o chat controla.'];

        $feitos = 0;
        foreach (array_slice($aparelhos, 0, GOVEE_MAX) as $id) {
            [$sku, $dev] = govee_parte((string) $id);
            if ($sku === '') continue;

            if ($ordem['acao'] === 'desligar') {
                if (govee_manda($cfg, $sku, $dev, [
                    'type' => 'devices.capabilities.on_off', 'instance' => 'powerSwitch', 'value' => 0,
                ])) $feitos++;
                continue;
            }

            $ok = govee_manda($cfg, $sku, $dev, [
                'type' => 'devices.capabilities.on_off', 'instance' => 'powerSwitch', 'value' => 1,
            ]);

            if ($ordem['cor']) {
                [$r, $g, $b] = luz_rgb((string) $ordem['cor']);
                /* A Govee quer a cor como UM número: r<<16 | g<<8 | b. */
                $ok = govee_manda($cfg, $sku, $dev, [
                    'type' => 'devices.capabilities.color_setting', 'instance' => 'colorRgb',
                    'value' => ($r << 16) + ($g << 8) + $b,
                ]) && $ok;
            }

            if ($ordem['brilho'] !== null) {
                $ok = govee_manda($cfg, $sku, $dev, [
                    'type' => 'devices.capabilities.range', 'instance' => 'brightness',
                    'value' => max(1, min(100, (int) $ordem['brilho'])),
                ]) && $ok;
            }

            if ($ok) $feitos++;
        }

        /* A Govee não tem "volta depois": o que existe é mandar de novo. O
           núcleo agenda isso quando dá; aqui a ordem termina onde chegou. */
        return $feitos ? ['ok' => true] : ['ok' => false, 'erro' => 'a Govee não obedeceu'];
    },
];
