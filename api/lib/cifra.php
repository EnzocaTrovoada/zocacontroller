<?php
/**
 * Cifra autenticada, pro que precisa voltar a ser lido (o link do mod).
 *
 * Chave em cfg()['cifra']: 32 bytes em base64. Sem ela, ou torta,
 * cifra_pronta() é falso e quem chama segue sem guardar nada cifrado.
 *
 * O prefixo guarda com qual das duas foi cifrado: o que o openssl cifrou
 * continua abrindo depois que a hospedagem ligar o sodium.
 */
require_once __DIR__ . '/db.php';

function cifra_chave(): ?string
{
    $bruta = base64_decode((string) (cfg()['cifra'] ?? ''), true);
    return ($bruta !== false && strlen($bruta) === 32) ? $bruta : null;
}

function cifra_pronta(): bool
{
    return cifra_chave() !== null
        && (function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt'));
}

function cifra(#[\SensitiveParameter] string $texto): string
{
    $chave = cifra_chave();
    if ($chave === null) {
        throw new RuntimeException('Falta a chave "cifra" no config.php.');
    }

    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1.' . base64_encode($nonce . sodium_crypto_secretbox($texto, $nonce, $chave));
    }

    $iv  = random_bytes(12);
    $tag = '';
    $cru = openssl_encrypt($texto, 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cru === false) {
        throw new RuntimeException('O openssl não cifrou.');
    }
    return 'g1.' . base64_encode($iv . $tag . $cru);
}

/** O texto de volta; null se a chave não bate, se mexeram no dado ou se falta a extensão. */
function decifra(string $cifrado): ?string
{
    $chave = cifra_chave();
    $bin   = base64_decode(substr($cifrado, 3), true);
    if ($chave === null || $bin === false) {
        return null;
    }

    try {
        switch (substr($cifrado, 0, 3)) {
            case 's1.':
                $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                $texto = sodium_crypto_secretbox_open(substr($bin, $n), substr($bin, 0, $n), $chave);
                break;
            case 'g1.':
                /* A tag sai sempre com 16 bytes: o openssl_decrypt aceita tag
                   mais curta, e uma de 1 byte se acerta no chute. */
                if (strlen($bin) < 28) return null;
                $texto = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', $chave, OPENSSL_RAW_DATA,
                                         substr($bin, 0, 12), substr($bin, 12, 16));
                break;
            default:
                return null;
        }
    } catch (Throwable $e) {
        return null;   // extensão que falta ou nonce torto lançam em vez de devolver false
    }

    return $texto === false ? null : $texto;
}
