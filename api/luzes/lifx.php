<?php
/**
 * LIFX — nuvem oficial (api.lifx.com/v1).
 *
 * Contrato em _MODELO.php.
 *
 * O token sai de cloud.lifx.com/settings. Limite de 120 chamadas por minuto
 * por token, e um seletor com vírgula resolve várias lâmpadas numa chamada
 * só — é por isso que aqui nunca tem laço por aparelho.
 */

const LIFX_BASE = 'https://api.lifx.com/v1';

function lifx_cabecalho(array $cfg): array
{
    return ['Authorization: Bearer ' . (string) ($cfg['token'] ?? ''), 'Content-Type: application/json'];
}

function lifx_seletor(array $aparelhos): string
{
    if (!$aparelhos) return 'all';
    return implode(',', array_map(fn($id) => 'id:' . rawurlencode((string) $id), $aparelhos));
}

return [
    'id'    => 'lifx',
    'nome'  => 'LIFX',
    'ajuda' => 'Entre em cloud.lifx.com, vá em Settings e gere um token.',
    'nuvem' => true,

    'campos' => [
        ['chave' => 'token', 'rotulo' => 'Token da LIFX', 'segredo' => true],
    ],

    'testar' => function (array $cfg): array {
        [$http, $r] = luz_http('GET', LIFX_BASE . '/lights/all', lifx_cabecalho($cfg));

        if ($http === 401) return ['ok' => false, 'erro' => 'A LIFX recusou esse token. Gere outro.'];
        if ($http !== 200) return ['ok' => false, 'erro' => 'A LIFX não respondeu agora. Tente de novo.'];

        $aps = [];
        foreach ($r as $l) {
            if (empty($l['id'])) continue;
            $aps[] = ['id' => (string) $l['id'], 'nome' => (string) ($l['label'] ?? $l['id'])];
        }
        if (!$aps) return ['ok' => false, 'erro' => 'O token vale, mas essa conta não tem lâmpada nenhuma.'];

        return ['ok' => true, 'aparelhos' => $aps];
    },

    'aplicar' => function (array $cfg, array $ordem, array $aparelhos): array {
        $sel = lifx_seletor($aparelhos);
        $cab = lifx_cabecalho($cfg);

        if ($ordem['acao'] === 'desligar') {
            [$http] = luz_http('PUT', LIFX_BASE . '/lights/' . $sel . '/state', $cab,
                ['power' => 'off', 'duration' => 0.3]);
            return $http >= 200 && $http < 300 ? ['ok' => true] : ['ok' => false, 'erro' => 'a LIFX não obedeceu'];
        }

        /* MS MAIOR QUE ZERO USA O EFEITO, NÃO O ESTADO.

           O efeito 'pulse' devolve a lâmpada ao que ela estava quando
           acaba. Guardar a cor antiga aqui e repor depois daria errado:
           entre uma coisa e outra a pessoa pode ter mexido no aplicativo, e
           aí a gente reporia uma cor que já não era a dela. */
        if ((int) $ordem['ms'] > 0 || $ordem['acao'] === 'piscar') {
            $ms = (int) $ordem['ms'] ?: 1200;
            [$http] = luz_http('POST', LIFX_BASE . '/lights/' . $sel . '/effects/pulse', $cab, [
                'color'    => $ordem['cor'] ?: '#FFFFFF',
                'period'   => max(0.2, $ms / 1000),
                'cycles'   => 1,
                'power_on' => true,
            ]);
            return $http >= 200 && $http < 300 ? ['ok' => true] : ['ok' => false, 'erro' => 'a LIFX não obedeceu'];
        }

        $corpo = ['power' => 'on', 'duration' => 0.3];
        if ($ordem['cor'])                 $corpo['color'] = $ordem['cor'];
        if ($ordem['brilho'] !== null)     $corpo['brightness'] = max(0, min(100, (int) $ordem['brilho'])) / 100;

        [$http] = luz_http('PUT', LIFX_BASE . '/lights/' . $sel . '/state', $cab, $corpo);

        if ($http === 401) return ['ok' => false, 'erro' => 'A LIFX recusou o token.'];
        if ($http === 429) return ['ok' => false, 'erro' => 'A LIFX pediu pra esperar um pouco.'];
        return $http >= 200 && $http < 300 ? ['ok' => true] : ['ok' => false, 'erro' => 'a LIFX não obedeceu'];
    },
];
