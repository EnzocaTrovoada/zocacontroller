<?php
/**
 * Quem do site está transmitindo AGORA.
 *
 * Serve pro selo vermelho do feed. Uma pergunta à Twitch por minuto, pro
 * site inteiro — e não uma por visita, nem uma por post: a Twitch responde
 * cem canais de uma vez, e o site tem bem menos que cem.
 *
 * A resposta mora na tabela 'vitrine', que já é um armário de chave e
 * valor com hora da última mexida. Tabela nova pra guardar uma lista que
 * vale sessenta segundos seria tabela demais.
 *
 * NADA AQUI PODE DERRUBAR O FEED. Twitch fora do ar, tabela que ainda não
 * existe, token vencido: tudo cai no catch e o feed sai sem o selo, que é
 * exatamente o que ele fazia antes disto existir.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/twitch.php';

const AOVIVO_CHAVE = 'ao_vivo';
const AOVIVO_SEG   = 60;     /* de pé em pé; a live não começa e acaba em um minuto */

/** O que está guardado, e há quanto tempo. */
function ao_vivo_guardado(): array
{
    $st = db()->prepare('SELECT valor, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade
                           FROM vitrine WHERE chave = ?');
    $st->execute([AOVIVO_CHAVE]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return [null, PHP_INT_MAX];

    $v = json_decode((string) $l['valor'], true);
    return [is_array($v) ? $v : [], (int) $l['idade']];
}

/**
 * Os logins que estão no ar, em minúsculas.
 *
 * Memorizado por requisição: o feed chama isto uma vez por autor, e a
 * pergunta é a mesma pra todos eles.
 */
function ao_vivo_agora(): array
{
    static $memoria = null;
    if ($memoria !== null) return $memoria;
    $memoria = [];

    try {
        [$guardado, $idade] = ao_vivo_guardado();
        if ($guardado !== null && $idade <= AOVIVO_SEG) return $memoria = $guardado;

        /* A LINHA É TOMADA ANTES DE PERGUNTAR À TWITCH.

           Sem isto, dez visitas no mesmo segundo viram dez perguntas. Quem
           conseguir mudar a hora é quem vai perguntar; os outros respondem
           com o que já tinha, que está velho de um minuto e não de um dia. */
        if ($guardado === null) {
            db()->prepare('INSERT IGNORE INTO vitrine (chave, valor) VALUES (?, ?)')
                ->execute([AOVIVO_CHAVE, '[]']);
        }

        $tomou = db()->prepare(
            'UPDATE vitrine SET atualizado_em = NOW()
              WHERE chave = ? AND TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) > ?'
        );
        $tomou->execute([AOVIVO_CHAVE, AOVIVO_SEG]);
        if ($tomou->rowCount() === 0 && $guardado !== null) return $memoria = $guardado;

        $logins = db()->query("SELECT login FROM usuarios WHERE login IS NOT NULL AND login <> ''")
                      ->fetchAll(PDO::FETCH_COLUMN);
        $logins = array_values(array_unique(array_map('strtolower', array_map('strval', $logins))));

        $vivos = [];
        foreach (array_chunk($logins, 100) as $lote) {
            if (!$lote) continue;
            [$http, $r] = tw_helix_app('GET',
                '/streams?user_login=' . implode('&user_login=', array_map('rawurlencode', $lote)));
            if ($http !== 200) continue;
            foreach (($r['data'] ?? []) as $s) {
                if (!empty($s['user_login'])) $vivos[] = strtolower((string) $s['user_login']);
            }
        }

        db()->prepare('UPDATE vitrine SET valor = ?, atualizado_em = NOW() WHERE chave = ?')
            ->execute([json_encode(array_values(array_unique($vivos))), AOVIVO_CHAVE]);

        $memoria = $vivos;
    } catch (Throwable $e) {
        $memoria = [];
    }

    return $memoria;
}

/** Este canal está no ar? */
function ao_vivo_esta(string $login): bool
{
    return $login !== '' && in_array(strtolower($login), ao_vivo_agora(), true);
}
