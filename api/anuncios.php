<?php
/**
 * Anúncios de tempo em tempo, sozinhos.
 *
 * GET                      → como está, e o que a Twitch diz do próximo
 * POST {ligado, minutos, duracao, espera_ini}  → muda
 * POST {agora: 1}          → roda um agora (botão do painel)
 * POST {tique: 1, ao_vivo, minutos_de_live}    → a ponte perguntando a hora
 *
 * POR QUE ALGUÉM QUERERIA ISTO.
 *
 * Parece contra o próprio interesse, e é o contrário. Rodar anúncio no
 * meio rende "tempo livre de pré-roll": pelo tanto que você roda, a Twitch
 * para de jogar propaganda na cara de quem acabou de chegar. Quem nunca
 * roda é quem mais castiga o espectador novo — ele abre o canal e leva
 * anúncio antes de ver qualquer coisa, e vai embora.
 *
 * O RELÓGIO É DAQUI, e não da ponte. Ela só pergunta; quem decide é o
 * UPDATE com a condição de tempo, que só passa uma vez. Duas fontes do OBS
 * abertas perguntam as duas e só a primeira leva.
 *
 * SÓ O DONO DO CANAL RODA ANÚNCIO. A Twitch não aceita editor nem
 * moderador nesse endereço, então o token é sempre o de quem transmite.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/twitch.php';

cors();
$quem = quem_chama();
$uid  = (int) $quem['usuario_id'];

const ANUNCIO_MIN_MINUTOS = 5;      /* menos que isso a Twitch recusa mesmo */
const ANUNCIO_MAX_MINUTOS = 240;
const ANUNCIO_DURACOES    = [30, 60, 90, 120, 180];

/** A linha deste canal, com os padrões de quem nunca configurou. */
function anuncio_linha(int $uid): array
{
    $padrao = ['ligado' => 0, 'minutos' => 30, 'duracao' => 90, 'espera_ini' => 20,
               'ultimo_em' => null, 'ultimo_erro' => '', 'quantos' => 0];
    try {
        $st = db()->prepare('SELECT * FROM anuncios WHERE usuario_id = ?');
        $st->execute([$uid]);
        $l = $st->fetch();
        return $l ? array_merge($padrao, $l) : $padrao;
    } catch (Throwable $e) {
        return $padrao;              /* sem o SQL 059, ninguém tem anúncio */
    }
}

/** As permissões que a Twitch pede. Sem elas, não adianta tentar. */
function anuncio_falta(int $uid): string
{
    try {
        $esc = tw_escopos($uid);
    } catch (Throwable $e) {
        return '';
    }
    return in_array('channel:edit:commercial', $esc, true) ? '' : 'sem-permissao';
}

/**
 * Roda o anúncio. Devolve ['ok'=>bool, 'erro'=>string, 'espera'=>int].
 *
 * O retry_after da Twitch é o que impede a gente de insistir: ela diz em
 * quantos segundos aceita o próximo, e brigar com isso só gera recusa.
 */
function anuncio_roda(int $uid, int $duracao): array
{
    $bid = tw_broadcaster_id($uid);
    try {
        [$http, $r] = tw_helix($uid, 'POST', '/channels/commercial', [],
            ['broadcaster_id' => $bid, 'length' => $duracao]);
    } catch (Throwable $e) {
        return ['ok' => false, 'erro' => erro_publico($e)];
    }

    $d = $r['data'][0] ?? [];
    if ($http === 200 && (int) ($d['length'] ?? 0) > 0) {
        return ['ok' => true, 'espera' => (int) ($d['retry_after'] ?? 0),
                'recado' => (string) ($d['message'] ?? '')];
    }

    /* 400 aqui quase sempre é "não está ao vivo" ou "ainda no intervalo do
       anterior" — nenhum dos dois é defeito, e a mensagem deles já diz. */
    $msg = (string) ($d['message'] ?? $r['message'] ?? ('http ' . $http));
    if ($http === 401 || $http === 403) {
        $msg = 'Falta a permissão de rodar anúncio. Entre com a Twitch de novo.';
    }
    return ['ok' => false, 'erro' => mb_substr($msg, 0, 160)];
}

/** Guarda o que aconteceu, pra tela poder contar depois. */
function anuncio_anota(int $uid, array $r): void
{
    try {
        if (!empty($r['ok'])) {
            db()->prepare('UPDATE anuncios SET ultimo_erro = \'\', quantos = quantos + 1 WHERE usuario_id = ?')
                ->execute([$uid]);
        } else {
            db()->prepare('UPDATE anuncios SET ultimo_erro = ? WHERE usuario_id = ?')
                ->execute([mb_substr((string) ($r['erro'] ?? ''), 0, 160), $uid]);
        }
    } catch (Throwable $e) { /* o anúncio é que importa */ }
}

/* ================= ler ================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    exige_painel();
    $l = anuncio_linha($uid);

    $saida = [
        'ligado'      => (int) $l['ligado'],
        'minutos'     => (int) $l['minutos'],
        'duracao'     => (int) $l['duracao'],
        'espera_ini'  => (int) $l['espera_ini'],
        'ultimo_em'   => (string) ($l['ultimo_em'] ?? ''),
        'ultimo_erro' => (string) $l['ultimo_erro'],
        'quantos'     => (int) $l['quantos'],
        'falta'       => anuncio_falta($uid),
        'duracoes'    => ANUNCIO_DURACOES,
    ];

    /* O TEMPO LIVRE DE PRÉ-ROLL É O PLACAR DISTO TUDO.
       É o número que diz se valeu a pena: enquanto ele existe, quem chega
       no canal não leva anúncio na cara. Pede só se a permissão de leitura
       estiver lá — ela é separada da de rodar. */
    try {
        [$h, $r] = tw_helix($uid, 'GET', '/channels/ads', ['broadcaster_id' => tw_broadcaster_id($uid)]);
        if ($h === 200 && !empty($r['data'][0])) {
            $a = $r['data'][0];
            $saida['twitch'] = [
                'sem_preroll' => (int) ($a['preroll_free_time'] ?? 0),
                'proximo'     => (string) ($a['next_ad_at'] ?? ''),
                'ultimo'      => (string) ($a['last_ad_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) { /* sem channel:read:ads, a tela vive sem isso */ }

    json_saida($saida);
}

$d = corpo_json();

/* ================= a ponte perguntando a hora ================= */
if (!empty($d['tique'])) {
    $l = anuncio_linha($uid);
    if (!(int) $l['ligado']) json_saida(['ok' => true, 'desligado' => true]);

    /* Fora do ar não existe anúncio, e nos primeiros minutos não deve
       existir: a live está juntando gente e cortar isso é perder ela. */
    if (empty($d['ao_vivo'])) json_saida(['ok' => true, 'fora' => true]);
    $vivo = (int) ($d['minutos_de_live'] ?? 0);
    if ($vivo < (int) $l['espera_ini']) json_saida(['ok' => true, 'cedo' => true]);

    /* A CONDIÇÃO DE TEMPO VIVE NO UPDATE.
       Se ela morasse num if aqui em cima, duas pontes perguntando no mesmo
       segundo passariam as duas — e o canal levaria dois anúncios. */
    try {
        $marca = db()->prepare(
            'UPDATE anuncios SET ultimo_em = NOW()
              WHERE usuario_id = ?
                AND (ultimo_em IS NULL OR ultimo_em < DATE_SUB(NOW(), INTERVAL minutos MINUTE))'
        );
        $marca->execute([$uid]);
        if (!$marca->rowCount()) json_saida(['ok' => true, 'cedo' => true]);
    } catch (Throwable $e) {
        json_saida(['ok' => true, 'cedo' => true]);
    }

    responder_e_continuar();
    $r = anuncio_roda($uid, (int) $l['duracao']);
    anuncio_anota($uid, $r);
    exit;
}

/* Daqui pra baixo é o painel mexendo. */
exige_painel();

/* ================= rodar um agora ================= */
if (!empty($d['agora'])) {
    trava('anuncio-agora', 4, 300);
    $l = anuncio_linha($uid);
    $r = anuncio_roda($uid, (int) $l['duracao']);
    anuncio_anota($uid, $r);
    if (empty($r['ok'])) json_saida(['erro' => $r['erro']], 502);

    try {
        db()->prepare('UPDATE anuncios SET ultimo_em = NOW() WHERE usuario_id = ?')->execute([$uid]);
    } catch (Throwable $e) { /* rodou, que é o que importa */ }

    json_saida(['ok' => true, 'espera' => (int) ($r['espera'] ?? 0)]);
}

/* ================= salvar ================= */
$minutos = max(ANUNCIO_MIN_MINUTOS, min(ANUNCIO_MAX_MINUTOS, (int) ($d['minutos'] ?? 30)));
/* Lido UMA vez: com o ?? só na condição, um POST sem duracao caía no ramo
   verdadeiro e gravava zero. */
$pedida = (int) ($d['duracao'] ?? 90);
$duracao = in_array($pedida, ANUNCIO_DURACOES, true) ? $pedida : 90;
$inicio  = max(0, min(120, (int) ($d['espera_ini'] ?? 20)));
$ligado  = !empty($d['ligado']) ? 1 : 0;

if ($ligado && anuncio_falta($uid) !== '') {
    json_saida(['erro' => 'Falta a permissão de rodar anúncio. Entre com a Twitch de novo pra liberar.'], 400);
}

try {
    db()->prepare(
        'INSERT INTO anuncios (usuario_id, ligado, minutos, duracao, espera_ini)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE ligado = VALUES(ligado), minutos = VALUES(minutos),
                                 duracao = VALUES(duracao), espera_ini = VALUES(espera_ini)'
    )->execute([$uid, $ligado, $minutos, $duracao, $inicio]);
} catch (PDOException $e) {
    json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 059 no banco.')], 500);
}

json_saida(['ok' => true, 'ligado' => $ligado, 'minutos' => $minutos,
            'duracao' => $duracao, 'espera_ini' => $inicio]);
