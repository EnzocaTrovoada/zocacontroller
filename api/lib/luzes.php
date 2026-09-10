<?php
/**
 * O núcleo das luzes. Não conhece marca nenhuma.
 *
 * Cada marca é um arquivo em api/luzes/, achado sozinho por este arquivo.
 * O contrato está escrito inteiro em api/luzes/_MODELO.php, e é de
 * propósito que ele caiba numa leitura: somar uma marca não pode exigir
 * conhecer o resto do site.
 */
require_once __DIR__ . '/db.php';

const LUZ_TIMEOUT = 6;

/** Chamada HTTP pros drivers. Com tempo limite: marca fora do ar não pode
    segurar a requisição de quem está transmitindo. */
function luz_http(string $metodo, string $url, array $cabecalhos = [], $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => LUZ_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => $cabecalhos,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($corpo) ? $corpo : json_encode($corpo));
    }
    $bruto = curl_exec($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $d = json_decode((string) $bruto, true);
    return [$http, is_array($d) ? $d : []];
}

/** '#RRGGBB' em [r, g, b] de 0 a 255. */
function luz_rgb(string $hex): array
{
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

/** Todos os drivers que existem na pasta, pelo id. */
function luz_drivers(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (glob(__DIR__ . '/../luzes/*.php') ?: [] as $arq) {
        /* O modelo começa com _ e serve de documentação, não de driver. */
        if (basename($arq)[0] === '_') continue;
        try {
            $d = require $arq;
        } catch (Throwable $e) {
            continue;
        }
        if (!is_array($d) || empty($d['id']) || empty($d['aplicar'])) continue;
        if (!empty($d['oculto'])) continue;
        $cache[(string) $d['id']] = $d;
    }
    return $cache;
}

/** O que a tela precisa saber pra desenhar a lista de marcas. */
function luz_catalogo(): array
{
    $saida = [];
    foreach (luz_drivers() as $id => $d) {
        $saida[] = [
            'id'     => $id,
            'nome'   => (string) ($d['nome'] ?? $id),
            'ajuda'  => (string) ($d['ajuda'] ?? ''),
            'nuvem'  => !empty($d['nuvem']),
            'campos' => array_map(fn($c) => [
                'chave'   => (string) $c['chave'],
                'rotulo'  => (string) ($c['rotulo'] ?? $c['chave']),
                'segredo' => !empty($c['segredo']),
            ], $d['campos'] ?? []),
        ];
    }
    return $saida;
}

/** As contas de luz de um usuário, sem devolver credencial. */
function luz_contas(int $uid): array
{
    $st = db()->prepare('SELECT driver, aparelhos, ligado FROM luzes_contas WHERE usuario_id = ?');
    $st->execute([$uid]);

    $drivers = luz_drivers();
    $saida = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (!isset($drivers[$c['driver']])) continue;
        $saida[] = [
            'driver'    => (string) $c['driver'],
            'nome'      => (string) ($drivers[$c['driver']]['nome'] ?? $c['driver']),
            'ligado'    => (int) $c['ligado'],
            'aparelhos' => json_decode((string) ($c['aparelhos'] ?: '[]'), true) ?: [],
        ];
    }
    return $saida;
}

/**
 * Manda a mesma ordem pra todas as luzes ligadas da pessoa.
 *
 * Uma marca fora do ar não pode derrubar as outras: cada driver é chamado
 * dentro do seu próprio try, e o resultado diz quantas obedeceram.
 */
function luz_aplicar(int $uid, array $ordem): array
{
    $st = db()->prepare('SELECT driver, config, aparelhos FROM luzes_contas WHERE usuario_id = ? AND ligado = 1');
    $st->execute([$uid]);
    $contas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$contas) return ['ok' => false, 'erro' => 'Nenhuma luz conectada.'];

    $drivers = luz_drivers();
    $feitos = 0;
    $erro = '';

    foreach ($contas as $c) {
        $d = $drivers[$c['driver']] ?? null;
        if (!$d) continue;
        try {
            $r = ($d['aplicar'])(
                json_decode((string) ($c['config'] ?: '{}'), true) ?: [],
                $ordem,
                json_decode((string) ($c['aparelhos'] ?: '[]'), true) ?: []
            );
            if (!empty($r['ok'])) $feitos++;
            elseif ($erro === '') $erro = (string) ($r['erro'] ?? 'não deu');
        } catch (Throwable $e) {
            if ($erro === '') $erro = 'a marca não respondeu';
        }
    }

    return $feitos ? ['ok' => true, 'luzes' => $feitos] : ['ok' => false, 'erro' => $erro ?: 'não deu'];
}

/* Do mais fraco pro mais forte. Quarta cópia desta lista no projeto, e o
   conferir.js guarda as quatro: cargo fora de ordem aqui liberaria cena de
   dono pro chat inteiro sem ninguém perceber. */
const LUZ_CARGOS = ['chat', 'sub', 'vip', 'mod', 'supermod', 'dono'];

/* As cores que o chat sabe dizer sem saber hexadecimal. */
const LUZ_CORES = [
    'vermelho' => '#FF0000', 'verde'   => '#00FF00', 'azul'    => '#0000FF',
    'amarelo'  => '#FFD400', 'roxo'    => '#8000FF', 'rosa'    => '#FF3FA4',
    'laranja'  => '#FF7A00', 'ciano'   => '#00E5FF', 'branco'  => '#FFFFFF',
    'lilas'    => '#B98CFF', 'turquesa' => '#1FD6C0',
];

/**
 * Transforma o que veio do chat numa ordem.
 *
 * Aceita, nesta ordem: uma cena que a pessoa criou, um nome de cor, um
 * hexadecimal, "on"/"off". Cena primeiro porque é o que a pessoa escolheu
 * chamar assim — se ela criou uma cena "azul", é a cena dela que vale.
 */
function luz_entender(int $uid, string $texto): ?array
{
    $t = mb_strtolower(trim($texto));
    if ($t === '') return null;

    $st = db()->prepare('SELECT cor, brilho, ms, cargo FROM luzes_cenas WHERE usuario_id = ? AND palavra = ?');
    $st->execute([$uid, mb_substr($t, 0, 32)]);
    $cena = $st->fetch(PDO::FETCH_ASSOC);
    if ($cena) {
        return [
            'acao'   => $cena['cor'] ? 'cor' : 'ligar',
            'cor'    => $cena['cor'],
            'brilho' => $cena['brilho'] === null ? null : (int) $cena['brilho'],
            'ms'     => (int) $cena['ms'],
            'cargo'  => (string) $cena['cargo'],
        ];
    }

    $base = ['acao' => 'cor', 'cor' => null, 'brilho' => null, 'ms' => 0, 'cargo' => null];

    if ($t === 'off' || $t === 'apagar' || $t === 'desligar') return array_merge($base, ['acao' => 'desligar']);
    if ($t === 'on' || $t === 'acender' || $t === 'ligar')    return array_merge($base, ['acao' => 'ligar']);
    if ($t === 'piscar' || $t === 'flash')                    return array_merge($base, ['acao' => 'piscar', 'cor' => '#FFFFFF']);

    if (isset(LUZ_CORES[$t])) return array_merge($base, ['cor' => LUZ_CORES[$t]]);
    if (preg_match('/^#?([0-9a-f]{6}|[0-9a-f]{3})$/i', $t)) {
        return array_merge($base, ['cor' => '#' . strtoupper(ltrim($t, '#'))]);
    }

    return null;
}
