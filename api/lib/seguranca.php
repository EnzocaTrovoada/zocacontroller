<?php
require_once __DIR__ . '/db.php';

/** Chave aleatória para chave_publica, tokens de dispositivo, etc. */
function chave_nova(int $bytes = 16): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** Assina "carga|expiracao" para links do painel. Vale pouco tempo de propósito. */
function link_assinar(string $carga, int $segundos = 900): string
{
    $exp = time() + $segundos;
    $msg = $carga . '|' . $exp;
    return $msg . '|' . hash_hmac('sha256', $msg, cfg()['segredo_links']);
}

/** Devolve a carga se o link for legítimo e estiver no prazo; senão null. */
function link_verificar(string $token): ?string
{
    $p = explode('|', $token);
    if (count($p) !== 3) {
        return null;
    }
    [$carga, $exp, $sig] = $p;

    $esperado = hash_hmac('sha256', $carga . '|' . $exp, cfg()['segredo_links']);
    if (!hash_equals($esperado, $sig)) {
        return null;                       // assinatura não confere
    }
    if ((int) $exp < time()) {
        return null;                       // link vazado depois do prazo não serve
    }
    return $carga;
}

/**
 * Limite de tentativas por chave, ex.: limite_ok("pareamento:$ip", 5, 300).
 * Sem isso, um código de 6 dígitos cai em minutos.
 */
function limite_ok(string $chave, int $max, int $janela_seg): bool
{
    $janela = (int) floor(time() / $janela_seg);

    $st = db()->prepare(
        'INSERT INTO rate_limit (chave, janela, contagem) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE contagem = contagem + 1'
    );
    $st->execute([$chave, $janela]);

    $st = db()->prepare('SELECT contagem FROM rate_limit WHERE chave = ? AND janela = ?');
    $st->execute([$chave, $janela]);

    return ((int) $st->fetchColumn()) <= $max;
}

/**
 * Confere a assinatura do webhook do Mercado Pago.
 *
 * O header x-signature vem como "ts=<epoch>,v1=<hmac>". O texto assinado é
 *     id:<data.id>;request-id:<x-request-id>;ts:<ts>;
 * com data.id em MINÚSCULO. A chave é a "Assinatura secreta" do painel deles.
 *
 * ATENÇÃO: conferir na documentação oficial antes de subir para produção —
 * o formato desse template já mudou uma vez.
 */
function mp_webhook_valido(string $x_signature, string $x_request_id, string $data_id): bool
{
    $ts = null;
    $v1 = null;

    foreach (explode(',', $x_signature) as $parte) {
        $kv = explode('=', trim($parte), 2);
        if (count($kv) === 2) {
            if ($kv[0] === 'ts') { $ts = $kv[1]; }
            if ($kv[0] === 'v1') { $v1 = $kv[1]; }
        }
    }

    if ($ts === null || $v1 === null) {
        return false;
    }
    if (abs(time() - (int) $ts) > 300) {
        return false;                      // notificação velha: alguém reenviando
    }

    $modelo = 'id:' . strtolower($data_id) . ';request-id:' . $x_request_id . ';ts:' . $ts . ';';

    return hash_equals(hash_hmac('sha256', $modelo, cfg()['mercadopago']['webhook_secret']), $v1);
}
