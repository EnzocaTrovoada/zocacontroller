<?php
/**
 * Subathon automático.
 *
 * O overlay mora no relogio.zocahop.com e guarda "quando acaba". Somar tempo
 * é ler quanto falta, somar, e regravar — e quem faz isso é este servidor,
 * porque o código de dono do overlay não pode viajar numa URL que aparece em
 * print. Servidor falando com servidor não passa por navegador nenhum.
 *
 * GET               → configuração e os últimos eventos
 * POST {config}     → muda as regras (quanto cada coisa soma)
 * POST {acao:somar} → soma tempo. É a ponte quem chama, quando vê sub ou bits
 *                     no chat.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = quem_chama();

const CAMPOS = ['seg_sub1', 'seg_sub2', 'seg_sub3', 'seg_bits', 'seg_follow', 'seg_real', 'teto_evento'];

function config_de(int $usuario_id): ?array
{
    $st = db()->prepare('SELECT * FROM subathon WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    $c = $st->fetch();
    return $c ?: null;
}

/**
 * Lê o overlay no servidor do relógio, soma os segundos e regrava.
 *
 * Pausado guarda quanto falta; correndo guarda quando acaba. Somar em cada um
 * é uma conta diferente, e somar no campo errado faria o tempo sumir.
 */
function somar_no_overlay(array $c, int $segundos): array
{
    $base = rtrim(cfg()['relogio_base'] ?? 'https://relogio.zocahop.com', '/');

    $ch = curl_init($base . '/estilos/' . rawurlencode($c['slug']) . '.json?t=' . time());
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $cru  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || !$cru) {
        return ['ok' => false, 'erro' => 'Não achei o overlay do subathon. O link ainda existe?'];
    }
    $estilo = json_decode($cru, true);
    if (!is_array($estilo)) {
        return ['ok' => false, 'erro' => 'O overlay respondeu algo que não entendi.'];
    }

    $agora = time();
    if (($estilo['modo'] ?? 'pausado') === 'rodando') {
        // Correndo: empurra o fim para a frente. Se já passou, conta a partir
        // de agora — senão a primeira sub depois do zero somaria no passado.
        $fim = max((int) ($estilo['fim'] ?? 0), $agora);
        $estilo['fim'] = $fim + $segundos;
        $restante = $estilo['fim'] - $agora;
    } else {
        $estilo['restante'] = max(0, (int) ($estilo['restante'] ?? 0) + $segundos);
        $restante = $estilo['restante'];
    }

    $ch = curl_init($base . '/salvar.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POSTFIELDS     => json_encode([
            'acao'  => 'salvar',
            'slug'  => $c['slug'],
            'token' => $c['token'],
            'cfg'   => $estilo,
        ]),
    ]);
    $resp = json_decode((string) curl_exec($ch), true);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || empty($resp['ok'])) {
        return ['ok' => false, 'erro' => $resp['erro'] ?? 'O servidor do overlay recusou a gravação.'];
    }
    return ['ok' => true, 'restante' => $restante];
}

// ---------- leitura ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $c = config_de($quem['usuario_id']);
    if (!$c) {
        json_saida(['ligado' => false, 'configurado' => false]);
    }

    $st = db()->prepare(
        'SELECT tipo, quem, detalhe, segundos,
                TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS ha_segundos
           FROM subathon_eventos WHERE usuario_id = ? ORDER BY id DESC LIMIT 30'
    );
    $st->execute([$quem['usuario_id']]);

    json_saida([
        'configurado' => true,
        'ligado'      => (bool) $c['ligado'],
        'slug'        => $c['slug'],
        'regras'      => array_map('intval', array_intersect_key($c, array_flip(CAMPOS))),
        'eventos'     => $st->fetchAll(),
    ]);
}

$d = corpo_json();

// ---------- somar (a ponte chama) ----------
if (($d['acao'] ?? '') === 'somar') {
    if ($quem['tipo'] !== 'painel') {
        json_saida(['erro' => 'Só a ponte soma tempo.'], 403);
    }
    trava('subathon', 120, 60);

    $c = config_de($quem['usuario_id']);
    if (!$c)              json_saida(['erro' => 'Subathon não configurado.'], 400);
    if (!$c['ligado'])    json_saida(['ok' => true, 'ignorado' => 'subathon desligado']);

    $tipo  = (string) ($d['tipo'] ?? '');
    $chave = trim((string) ($d['chave'] ?? ''));
    if ($chave === '') json_saida(['erro' => 'Falta o id do evento.'], 400);

    // Quantos segundos, conforme a regra que o streamer escolheu.
    $qtd = max(0, (float) ($d['quantidade'] ?? 1));
    $segundos = match ($tipo) {
        'sub1'   => (int) $c['seg_sub1'] * (int) $qtd,
        'sub2'   => (int) $c['seg_sub2'] * (int) $qtd,
        'sub3'   => (int) $c['seg_sub3'] * (int) $qtd,
        'bits'   => (int) round($c['seg_bits'] * $qtd / 100),
        'follow' => (int) $c['seg_follow'],
        'real'   => (int) round($c['seg_real'] * $qtd),
        default  => -1,
    };

    if ($segundos < 0)  json_saida(['erro' => 'Tipo de evento desconhecido.'], 400);
    if ($segundos === 0) json_saida(['ok' => true, 'ignorado' => 'esse tipo está zerado']);

    // Teto por evento: um cheer gigante não pode virar dois dias de live.
    $segundos = min($segundos, (int) $c['teto_evento']);

    // Idempotência: o chat reentrega mensagem quando a conexão cai e volta.
    // O UNIQUE resolve antes de somar, não depois.
    try {
        db()->prepare(
            'INSERT INTO subathon_eventos (usuario_id, chave, tipo, quem, detalhe, segundos)
                  VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $quem['usuario_id'], mb_substr($chave, 0, 120), $tipo,
            mb_substr((string) ($d['quem'] ?? ''), 0, 64) ?: null,
            mb_substr((string) ($d['detalhe'] ?? ''), 0, 64) ?: null,
            $segundos,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_saida(['ok' => true, 'ignorado' => 'evento repetido']);
        }
        throw $e;
    }

    $r = somar_no_overlay($c, $segundos);
    if (empty($r['ok'])) {
        // Não deu para gravar: apaga o registro, senão o evento fica marcado
        // como contado e o tempo nunca entra.
        db()->prepare('DELETE FROM subathon_eventos WHERE usuario_id = ? AND chave = ?')
            ->execute([$quem['usuario_id'], mb_substr($chave, 0, 120)]);
        json_saida(['erro' => $r['erro']], 502);
    }

    json_saida(['ok' => true, 'somou' => $segundos, 'restante' => $r['restante']]);
}

// ---------- configurar ----------
$quem = exige_painel();

$slug  = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) ($d['slug'] ?? ''))));
$token = trim((string) ($d['token'] ?? ''));

if ($slug === '' || $token === '') {
    json_saida(['erro' => 'Cole o link do subathon e o código de dono dele.'], 400);
}

$valores = [];
foreach (CAMPOS as $campo) {
    $valores[$campo] = max(0, min(86400, (int) ($d[$campo] ?? 0)));
}

db()->prepare(
    'INSERT INTO subathon (usuario_id, slug, token, ligado, seg_sub1, seg_sub2, seg_sub3,
                           seg_bits, seg_follow, seg_real, teto_evento)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE slug = VALUES(slug), token = VALUES(token), ligado = VALUES(ligado),
          seg_sub1 = VALUES(seg_sub1), seg_sub2 = VALUES(seg_sub2), seg_sub3 = VALUES(seg_sub3),
          seg_bits = VALUES(seg_bits), seg_follow = VALUES(seg_follow), seg_real = VALUES(seg_real),
          teto_evento = VALUES(teto_evento)'
)->execute([
    $quem['usuario_id'], $slug, $token, empty($d['desligar']) ? 1 : 0,
    $valores['seg_sub1'], $valores['seg_sub2'], $valores['seg_sub3'],
    $valores['seg_bits'], $valores['seg_follow'], $valores['seg_real'],
    $valores['teto_evento'] ?: 7200,
]);

json_saida(['ok' => true]);
