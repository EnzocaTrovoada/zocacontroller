<?php
/**
 * A vitrine da página inicial: até três canais em destaque.
 *
 * Três fontes independentes, e cada uma responde uma pergunta diferente:
 *
 *   usuario  — alguém que usa o ZocaController e está no ar agora
 *   pro      — o mesmo, mas entre quem paga (é o que faz assinar valer algo
 *              além dos recursos)
 *   twitch   — qualquer canal pequeno em português, usuário do site ou não
 *
 * Cada fatia é escolhida UMA VEZ POR DIA e fica guardada. As duas primeiras
 * custam um pedido; a terceira custa dezenas, e é por isso que ela não pode
 * ser sorteada a cada visita — ver o comentário em vitrine_twitch().
 *
 * A leitura é pública: é vitrine, é pra ser vista por quem ainda não entrou.
 * Só o que MEXE nela exige ser admin.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/twitch.php';

cors();

const VITRINE_FATIAS = ['usuario', 'pro', 'twitch'];

/* Quantas páginas descer na lista da Twitch antes de olhar. Sorteado pra que
   a vitrine não caia sempre na mesma faixa de audiência, e com teto porque
   cada página é um pedido. */
const VITRINE_MIN_PAGINAS = 12;
const VITRINE_MAX_PAGINAS = 34;

/* O que conta como "pequeno". Zero espectador quase sempre é live recém-aberta
   sem ninguém pra ver; acima de trinta a pessoa já não precisa da ajuda. */
const VITRINE_MIN_VIEWERS = 1;
const VITRINE_MAX_VIEWERS = 30;

/* ------------------------------------------------------------------ *
 *  Estado guardado
 * ------------------------------------------------------------------ */

function vitrine_le(string $chave): ?array
{
    $st = db()->prepare('SELECT valor, atualizado_em FROM vitrine WHERE chave = ?');
    $st->execute([$chave]);
    $l = $st->fetch();
    if (!$l) return null;
    return ['valor' => json_decode((string) $l['valor'], true), 'em' => (string) $l['atualizado_em']];
}

function vitrine_grava(string $chave, $valor): void
{
    db()->prepare(
        'INSERT INTO vitrine (chave, valor, atualizado_em) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW()'
    )->execute([$chave, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
}

/**
 * A configuração da vitrine, com os padrões.
 *
 * 'fixo' é o canal que o Enzo escolheu à mão. Quando existe, ele manda em
 * cima do sorteio — é a saída de emergência pra quando o sorteado não pode
 * ficar ali, e também o jeito de destacar alguém de propósito.
 */
function vitrine_config(): array
{
    $c = vitrine_le('config');
    $c = is_array($c['valor'] ?? null) ? $c['valor'] : [];

    $saida = [];
    foreach (VITRINE_FATIAS as $f) {
        $saida[$f] = [
            'ligado'   => array_key_exists('ligado', $c[$f] ?? []) ? (bool) $c[$f]['ligado'] : true,
            'fixo'     => (string) ($c[$f]['fixo'] ?? ''),
            'categoria'=> (string) ($c[$f]['categoria'] ?? ''),
        ];
    }
    return $saida;
}

function vitrine_bloqueados(): array
{
    try {
        $l = db()->query('SELECT login FROM vitrine_bloqueio')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map('strtolower', $l);
    } catch (Throwable $e) {
        return [];
    }
}

/* ------------------------------------------------------------------ *
 *  Traduzindo o que a Twitch responde
 * ------------------------------------------------------------------ */

function vitrine_cartao(array $s): array
{
    /* A miniatura vem com {width} e {height} pra gente escolher o tamanho. */
    $thumb = str_replace(['{width}', '{height}'], ['440', '248'],
        (string) ($s['thumbnail_url'] ?? ''));

    return [
        'login'   => (string) ($s['user_login'] ?? ''),
        'nome'    => (string) ($s['user_name'] ?? ''),
        'titulo'  => mb_substr((string) ($s['title'] ?? ''), 0, 120),
        'jogo'    => (string) ($s['game_name'] ?? ''),
        'viewers' => (int) ($s['viewer_count'] ?? 0),
        'thumb'   => $thumb,
    ];
}

function vitrine_serve(array $s, array $bloqueados): bool
{
    if (empty($s['user_login'])) return false;
    /* Conteúdo adulto fora: isto vai parar na página inicial de um site que
       qualquer um abre. */
    if (!empty($s['is_mature'])) return false;
    return !in_array(strtolower((string) $s['user_login']), $bloqueados, true);
}

/** Quais destes logins estão no ar agora. Cem por pedido é o teto deles. */
function vitrine_quem_esta_ao_vivo(array $logins, array $bloqueados): array
{
    $vivos = [];
    foreach (array_chunk(array_values($logins), 100) as $lote) {
        $q = 'user_login=' . implode('&user_login=', array_map('rawurlencode', $lote));
        [$http, $r] = tw_http('GET', TW_HELIX . '/streams?' . $q, [
            'Authorization: Bearer ' . tw_token_do_app(),
            'Client-Id: ' . cfg()['twitch']['client_id'],
        ]);
        if ($http !== 200 || empty($r['data'])) continue;
        foreach ($r['data'] as $s) {
            if (vitrine_serve($s, $bloqueados)) $vivos[] = $s;
        }
    }
    return $vivos;
}

/* ------------------------------------------------------------------ *
 *  As três fontes
 * ------------------------------------------------------------------ */

/** Logins do site: todos, ou só quem tem plano pago. */
function vitrine_logins_do_site(bool $soPagantes): array
{
    $us = db()->query('SELECT id, login FROM usuarios WHERE login IS NOT NULL AND login <> \'\'')
              ->fetchAll(PDO::FETCH_ASSOC);
    if (!$soPagantes) return array_column($us, 'login');

    $pagantes = [];
    foreach ($us as $u) {
        $a = acesso_do_usuario((int) $u['id']);
        /* Beta não entra aqui de propósito: esta fatia existe pra mostrar que
           assinar tem retorno, e testador não assinou. */
        if ($a['ativo'] && $a['plano'] !== 'gratis') $pagantes[] = $u['login'];
    }
    return $pagantes;
}

function vitrine_do_site(bool $soPagantes, array $bloqueados): ?array
{
    $logins = vitrine_logins_do_site($soPagantes);
    if (!$logins) return null;

    $vivos = vitrine_quem_esta_ao_vivo($logins, $bloqueados);
    if (!$vivos) return null;

    return vitrine_cartao($vivos[random_int(0, count($vivos) - 1)]);
}

/**
 * Um canal pequeno qualquer, em português.
 *
 * O PORÉM: a Twitch devolve as lives ORDENADAS POR AUDIÊNCIA, da maior pra
 * menor — exatamente ao contrário do que a gente quer. Não existe "me dê uma
 * live pequena": tem que caminhar páginas pra baixo até chegar em quem tem
 * poucos espectadores. Por isso esta é a fatia cara, e por isso ela é
 * escolhida uma vez por dia e não a cada visita.
 */
function vitrine_twitch(string $categoria, array $bloqueados): ?array
{
    $base = ['language' => 'pt', 'first' => '100', 'type' => 'live'];

    /* Categoria escolhida à mão: a Twitch filtra por id, não por nome. */
    if ($categoria !== '') {
        [$h, $g] = tw_helix_app('GET', '/games', ['name' => $categoria]);
        if ($h === 200 && !empty($g['data'][0]['id'])) {
            $base['game_id'] = (string) $g['data'][0]['id'];
        }
    }

    $paginas = random_int(VITRINE_MIN_PAGINAS, VITRINE_MAX_PAGINAS);
    $cursor = '';
    $achadas = [];
    $ultimaPagina = [];

    for ($i = 0; $i < $paginas; $i++) {
        $q = $base;
        if ($cursor !== '') $q['after'] = $cursor;

        [$http, $r] = tw_helix_app('GET', '/streams', $q);
        if ($http !== 200 || empty($r['data'])) break;

        $ultimaPagina = $r['data'];
        $cursor = (string) ($r['pagination']['cursor'] ?? '');

        /* Acabou a lista antes das páginas sorteadas: esta é a última que
           existe, e é justamente onde estão os menores. Olha ela. */
        if ($cursor === '') break;
    }

    foreach ($ultimaPagina as $s) {
        $v = (int) ($s['viewer_count'] ?? 0);
        if ($v < VITRINE_MIN_VIEWERS || $v > VITRINE_MAX_VIEWERS) continue;
        if (!vitrine_serve($s, $bloqueados)) continue;
        $achadas[] = $s;
    }

    if (!$achadas) return null;
    return vitrine_cartao($achadas[random_int(0, count($achadas) - 1)]);
}

/** Um canal específico, escolhido à mão. Só aparece se estiver no ar. */
function vitrine_fixo(string $login, array $bloqueados): ?array
{
    $vivos = vitrine_quem_esta_ao_vivo([$login], $bloqueados);
    return $vivos ? vitrine_cartao($vivos[0]) : null;
}

function vitrine_sortear(string $fatia, array $cfg, array $bloqueados): ?array
{
    if (($cfg['fixo'] ?? '') !== '') return vitrine_fixo($cfg['fixo'], $bloqueados);

    if ($fatia === 'usuario') return vitrine_do_site(false, $bloqueados);
    if ($fatia === 'pro')     return vitrine_do_site(true, $bloqueados);
    return vitrine_twitch((string) ($cfg['categoria'] ?? ''), $bloqueados);
}

/* ------------------------------------------------------------------ *
 *  Mexer na vitrine — só admin
 * ------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $quem = exige_painel();

    $ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
    $ad->execute([(int) $quem['usuario_id']]);
    if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

    $d = corpo_json();
    $acao = (string) ($d['acao'] ?? '');

    if ($acao === 'config') {
        $cfg = vitrine_config();
        foreach (VITRINE_FATIAS as $f) {
            if (!isset($d[$f]) || !is_array($d[$f])) continue;
            $cfg[$f]['ligado']    = !empty($d[$f]['ligado']);
            $cfg[$f]['fixo']      = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d[$f]['fixo'] ?? '')));
            $cfg[$f]['categoria'] = mb_substr(trim((string) ($d[$f]['categoria'] ?? '')), 0, 80);
        }
        vitrine_grava('config', $cfg);
        /* Mudou a regra, o sorteio de hoje não vale mais: apagar as escolhas
           faz a próxima visita sortear de novo já com a regra nova. */
        foreach (VITRINE_FATIAS as $f) {
            db()->prepare('DELETE FROM vitrine WHERE chave = ?')->execute(['fatia_' . $f]);
        }
        json_saida(['ok' => true, 'config' => $cfg]);
    }

    if ($acao === 'sortear') {
        $f = (string) ($d['fatia'] ?? '');
        if (!in_array($f, VITRINE_FATIAS, true)) json_saida(['erro' => 'Fatia desconhecida.'], 400);
        db()->prepare('DELETE FROM vitrine WHERE chave = ?')->execute(['fatia_' . $f]);
        json_saida(['ok' => true]);
    }

    if ($acao === 'banir') {
        $login = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d['login'] ?? '')));
        if ($login === '') json_saida(['erro' => 'Falta o canal.'], 400);

        db()->prepare(
            'INSERT INTO vitrine_bloqueio (login, motivo) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)'
        )->execute([$login, mb_substr(trim((string) ($d['motivo'] ?? '')), 0, 160) ?: null]);

        /* Some da vitrine AGORA, não amanhã: quem é banido é banido porque
           não pode estar ali neste momento. */
        foreach (VITRINE_FATIAS as $f) {
            $g = vitrine_le('fatia_' . $f);
            if (strtolower((string) ($g['valor']['login'] ?? '')) === $login) {
                db()->prepare('DELETE FROM vitrine WHERE chave = ?')->execute(['fatia_' . $f]);
            }
        }
        json_saida(['ok' => true]);
    }

    if ($acao === 'desbanir') {
        $login = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($d['login'] ?? '')));
        db()->prepare('DELETE FROM vitrine_bloqueio WHERE login = ?')->execute([$login]);
        json_saida(['ok' => true]);
    }

    if ($acao === 'estado') {
        json_saida([
            'config'     => vitrine_config(),
            'bloqueados' => db()->query('SELECT login, motivo, criado_em FROM vitrine_bloqueio ORDER BY criado_em DESC')
                                ->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* ------------------------------------------------------------------ *
 *  A leitura pública
 * ------------------------------------------------------------------ */

$cfg = vitrine_config();
$bloqueados = vitrine_bloqueados();
$hoje = date('Y-m-d');
$fatias = [];

foreach (VITRINE_FATIAS as $f) {
    if (!$cfg[$f]['ligado']) continue;

    $g = vitrine_le('fatia_' . $f);
    $valeAinda = $g && substr($g['em'], 0, 10) === $hoje;

    if ($valeAinda) {
        $cartao = $g['valor'];
    } else {
        /* Quem chegou primeiro depois da virada paga a conta de sortear. Se
           der errado, vale a escolha de ontem: canal de ontem na vitrine é
           bem menos ruim do que um buraco na página inicial. */
        try {
            $cartao = vitrine_sortear($f, $cfg[$f], $bloqueados);
        } catch (Throwable $e) {
            $cartao = null;
        }

        if ($cartao) vitrine_grava('fatia_' . $f, $cartao);
        elseif ($g) $cartao = $g['valor'];
    }

    /* Banido depois de sorteado não pode continuar aparecendo até amanhã. */
    if ($cartao && in_array(strtolower((string) ($cartao['login'] ?? '')), $bloqueados, true)) {
        $cartao = null;
    }

    if ($cartao) $fatias[$f] = $cartao;
}

/* Cinco minutos de cache na borda: a vitrine muda uma vez por dia, e cada
   visita da página inicial não precisa acordar o banco. */
header('Cache-Control: public, max-age=300');

json_saida(['fatias' => $fatias]);
