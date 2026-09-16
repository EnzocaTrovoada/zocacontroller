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
require_once __DIR__ . '/cifra.php';

const LUZ_TIMEOUT = 6;

/* A CREDENCIAL DA LUZ É GUARDADA CIFRADA.

   Um token da LIFX, da Govee ou da Philips controla a casa de alguém. Se o
   banco vazar, o que sai daqui tem que ser lixo sem a chave, que mora no
   config.php e não no banco.

   O que foi guardado antes disto continua abrindo, e é regravado cifrado
   na primeira vez que for lido. */
function luz_cfg_fecha(array $cfg): string
{
    $json = (string) json_encode($cfg);
    return cifra_pronta() ? cifra($json) : $json;
}

function luz_cfg_abre(?string $guardado): array
{
    $g = (string) $guardado;
    if ($g === '') return [];
    if (strncmp($g, 's1.', 3) === 0 || strncmp($g, 'g1.', 3) === 0) {
        $g = (string) decifra($g);
    }
    $d = json_decode($g, true);
    return is_array($d) ? $d : [];
}

/** Credencial ainda em texto puro vira cifrada assim que é lida. */
function luz_cfg_migra(int $uid, string $driver, string $guardado, array $cfg): void
{
    if (!$cfg || !cifra_pronta()) return;
    if (strncmp($guardado, 's1.', 3) === 0 || strncmp($guardado, 'g1.', 3) === 0) return;
    try {
        db()->prepare('UPDATE luzes_contas SET config = ? WHERE usuario_id = ? AND driver = ?')
            ->execute([luz_cfg_fecha($cfg), $uid, $driver]);
    } catch (Throwable $e) { /* fica pra próxima leitura */ }
}

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

/**
 * A mesma chamada, com senha no esquema Digest.
 *
 * A Philips só aceita o token dela assim: a primeira resposta é um 401 com
 * o desafio, e a segunda leva o hash. O curl faz essa ida e volta sozinho —
 * escrever o MD5 na mão aqui seria repetir o que ele já sabe.
 */
function luz_http_digest(string $metodo, string $url, string $usuario, string $senha,
                         array $cabecalhos = [], $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => LUZ_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => $cabecalhos,
        CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
        CURLOPT_USERPWD        => $usuario . ':' . $senha,
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
            /* Marca que conecta por autorização não tem campo pra preencher:
               a tela mostra um botão que leva pro site da marca. */
            'oauth'  => !empty($d['oauth']),
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

/** A config guardada de uma marca — tokens inclusive. Nunca vai pra tela. */
function luz_config(int $uid, string $driver): array
{
    $st = db()->prepare('SELECT config FROM luzes_contas WHERE usuario_id = ? AND driver = ?');
    $st->execute([$uid, $driver]);
    $guardado = (string) ($st->fetchColumn() ?: '');
    $cfg = luz_cfg_abre($guardado);
    luz_cfg_migra($uid, $driver, $guardado, $cfg);
    return $cfg;
}

/** Guarda a config sem encostar nos aparelhos que a pessoa escolheu. */
function luz_config_grava(int $uid, string $driver, array $cfg): void
{
    db()->prepare(
        'INSERT INTO luzes_contas (usuario_id, driver, config, aparelhos, ligado)
              VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE config = VALUES(config), ligado = 1'
    )->execute([$uid, $driver, luz_cfg_fecha($cfg), '[]']);
}

/**
 * Renova o acesso antes de usar, quando a marca é das que vencem.
 *
 * Quem conectou por autorização tem um acesso com prazo. Renovar só quando
 * a chamada falha custaria a primeira ordem de cada semana — a luz não
 * mudaria de cor e ninguém saberia por quê. Então renova antes, e o token
 * novo já fica guardado.
 */
function luz_renova(int $uid, string $driver, array $d, array $cfg): array
{
    $renovar = $d['oauth']['renovar'] ?? null;
    if (!$renovar || empty($cfg['expira'])) return $cfg;
    if (time() < (int) $cfg['expira'] - 120) return $cfg;

    try {
        $novo = $renovar($cfg);
    } catch (Throwable $e) {
        return $cfg;      /* marca fora do ar: tenta com o que tem */
    }
    if (!is_array($novo) || empty($novo['expira'])) return $cfg;

    luz_config_grava($uid, $driver, $novo);
    return $novo;
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
            $cfgAberta = luz_cfg_abre($c['config']);
            luz_cfg_migra($uid, (string) $c['driver'], (string) $c['config'], $cfgAberta);
            $r = ($d['aplicar'])(
                luz_renova($uid, (string) $c['driver'], $d, $cfgAberta),
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
