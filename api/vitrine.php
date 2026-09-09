<?php
/**
 * A vitrine da página inicial.
 *
 * Três fontes pro carrossel, e cada uma responde uma pergunta diferente:
 *
 *   usuario  — alguém que usa o ZocaController
 *   pro      — o mesmo, mas entre quem paga
 *   twitch   — um canal pequeno em português, usuário do site ou não
 *
 * Mais os TÓPICOS EM ALTA, que saem de uma leitura só e dizem o que está
 * rendendo audiência em português agora.
 *
 * ---------------------------------------------------------------------
 * DUAS COISAS SEPARADAS, COM PREÇOS MUITO DIFERENTES:
 *
 *   ESCOLHER quem aparece é caro. A Twitch devolve as lives ordenadas por
 *   audiência da maior pra menor, e não existe "me dê uma pequena" — tem que
 *   caminhar dezenas de páginas. Isso acontece UMA VEZ POR DIA e guarda uma
 *   LISTA de candidatos, não um só.
 *
 *   CONFERIR se essa pessoa ainda está no ar é barato: um pedido resolve cem
 *   canais. Isso acontece a cada poucos minutos.
 *
 * Misturar os dois foi o erro da primeira versão: com escolha diária, um
 * canal sorteado de manhã continuava anunciado como "ao vivo" à noite, muito
 * depois de ter desligado. Cartaz mentindo é pior do que cartaz vazio.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/twitch.php';

cors();

const VITRINE_FATIAS = ['usuario', 'pro', 'twitch'];

/* De quanto em quanto tempo se reconfere quem está no ar. Cinco minutos é
   curto o bastante pra não mentir e longo o bastante pra ninguém sentir. */
const VITRINE_FRESCOR = 300;

/* Quantas páginas descer na lista da Twitch atrás dos canais pequenos. */
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
    $st = db()->prepare('SELECT valor, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade,
                                DATE(atualizado_em) AS dia
                           FROM vitrine WHERE chave = ?');
    $st->execute([$chave]);
    $l = $st->fetch();
    if (!$l) return null;

    /* A idade vem do BANCO, não do PHP. Se um estiver num fuso e o outro
       noutro, comparar data de lá com data daqui erraria o dia inteiro — e o
       erro só apareceria na virada, que é quando ninguém está olhando. */
    return [
        'valor' => json_decode((string) $l['valor'], true),
        'idade' => (int) $l['idade'],
        'dia'   => (string) $l['dia'],
    ];
}

function vitrine_grava(string $chave, $valor): void
{
    db()->prepare(
        'INSERT INTO vitrine (chave, valor, atualizado_em) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW()'
    )->execute([$chave, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
}

function vitrine_config(): array
{
    $c = vitrine_le('config');
    $c = is_array($c['valor'] ?? null) ? $c['valor'] : [];

    $saida = [];
    foreach (VITRINE_FATIAS as $f) {
        $saida[$f] = [
            'ligado'    => array_key_exists('ligado', $c[$f] ?? []) ? (bool) $c[$f]['ligado'] : true,
            'fixo'      => (string) ($c[$f]['fixo'] ?? ''),
            'categoria' => (string) ($c[$f]['categoria'] ?? ''),
        ];
    }
    $saida['idioma'] = (string) ($c['idioma'] ?? 'pt');
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
    $thumb = str_replace(['{width}', '{height}'], ['640', '360'],
        (string) ($s['thumbnail_url'] ?? ''));

    return [
        'login'   => (string) ($s['user_login'] ?? ''),
        'nome'    => (string) ($s['user_name'] ?? ''),
        'titulo'  => mb_substr((string) ($s['title'] ?? ''), 0, 120),
        'jogo'    => (string) ($s['game_name'] ?? ''),
        'viewers' => (int) ($s['viewer_count'] ?? 0),
        'thumb'   => $thumb,
        'aovivo'  => true,
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
function vitrine_ao_vivo(array $logins, array $bloqueados): array
{
    $vivos = [];
    foreach (array_chunk(array_values(array_unique($logins)), 100) as $lote) {
        if (!$lote) continue;
        $q = 'user_login=' . implode('&user_login=', array_map('rawurlencode', $lote));
        [$http, $r] = tw_helix_app('GET', '/streams?' . $q);
        if ($http !== 200 || empty($r['data'])) continue;
        foreach ($r['data'] as $s) {
            if (vitrine_serve($s, $bloqueados)) $vivos[] = $s;
        }
    }
    return $vivos;
}

/**
 * O cartão de quem está com a live FECHADA.
 *
 * Existe porque painel que some quando ninguém está no ar deixa a página
 * inicial cheia de buraco — e, de fora, buraco e defeito são a mesma imagem.
 * Melhor mostrar o canal desligado, dizendo que está desligado, do que não
 * mostrar nada.
 */
function vitrine_cartao_offline(string $login, array $bloqueados): ?array
{
    if (in_array(strtolower($login), $bloqueados, true)) return null;

    [$h1, $u] = tw_helix_app('GET', '/users', ['login' => $login]);
    if ($h1 !== 200 || empty($u['data'][0])) return null;
    $uu = $u['data'][0];

    /* O que ele estava transmitindo da última vez. Serve de contexto: "canal
       de Hollow Knight" diz muito mais do que só um nome. */
    $titulo = '';
    $jogo = '';
    [$h2, $c] = tw_helix_app('GET', '/channels', ['broadcaster_id' => (string) $uu['id']]);
    if ($h2 === 200 && !empty($c['data'][0])) {
        $titulo = mb_substr((string) ($c['data'][0]['title'] ?? ''), 0, 120);
        $jogo   = (string) ($c['data'][0]['game_name'] ?? '');
    }

    return [
        'login'   => (string) $uu['login'],
        'nome'    => (string) ($uu['display_name'] ?? $uu['login']),
        'titulo'  => $titulo,
        'jogo'    => $jogo,
        'viewers' => 0,
        /* Sem live não existe miniatura: o que existe é a arte do canal. */
        'thumb'   => (string) ($uu['offline_image_url'] ?? ''),
        'foto'    => (string) ($uu['profile_image_url'] ?? ''),
        'aovivo'  => false,
    ];
}

/* ------------------------------------------------------------------ *
 *  As três fontes
 * ------------------------------------------------------------------ */

function vitrine_logins_do_site(bool $soPagantes): array
{
    $us = db()->query("SELECT id, login FROM usuarios WHERE login IS NOT NULL AND login <> ''")
              ->fetchAll(PDO::FETCH_ASSOC);
    if (!$soPagantes) return array_column($us, 'login');

    $pagantes = [];
    foreach ($us as $u) {
        $a = acesso_do_usuario((int) $u['id']);
        /* Beta não entra: esta fatia existe pra mostrar que assinar tem
           retorno, e testador não assinou. */
        if ($a['ativo'] && $a['plano'] !== 'gratis') $pagantes[] = $u['login'];
    }
    return $pagantes;
}

function vitrine_do_site(bool $soPagantes, array $bloqueados): ?array
{
    $logins = vitrine_logins_do_site($soPagantes);
    $logins = array_values(array_filter($logins, fn($l) => !in_array(strtolower($l), $bloqueados, true)));
    if (!$logins) return null;

    $vivos = vitrine_ao_vivo($logins, $bloqueados);
    if ($vivos) return vitrine_cartao($vivos[random_int(0, count($vivos) - 1)]);

    /* Ninguém no ar: mostra alguém desligado mesmo. */
    return vitrine_cartao_offline($logins[random_int(0, count($logins) - 1)], $bloqueados);
}

/**
 * A lista de candidatos pequenos da Twitch. Esta é a parte cara.
 *
 * Guarda VÁRIOS, não um. Assim a conferência de quem está no ar — que é
 * barata — tem de onde escolher durante o dia inteiro sem repetir a
 * caminhada.
 */
function vitrine_pool_twitch(string $categoria, string $idioma): array
{
    $base = ['language' => ($idioma ?: 'pt'), 'first' => '100', 'type' => 'live'];

    if ($categoria !== '') {
        [$h, $g] = tw_helix_app('GET', '/games', ['name' => $categoria]);
        if ($h === 200 && !empty($g['data'][0]['id'])) $base['game_id'] = (string) $g['data'][0]['id'];
    }

    $paginas = random_int(VITRINE_MIN_PAGINAS, VITRINE_MAX_PAGINAS);
    $cursor = '';
    $ultima = [];

    for ($i = 0; $i < $paginas; $i++) {
        $q = $base;
        if ($cursor !== '') $q['after'] = $cursor;

        [$http, $r] = tw_helix_app('GET', '/streams', $q);
        if ($http !== 200 || empty($r['data'])) break;

        $ultima = $r['data'];
        $cursor = (string) ($r['pagination']['cursor'] ?? '');
        /* Acabou a lista antes das páginas sorteadas: esta é a última que
           existe, e é justamente onde estão os menores. */
        if ($cursor === '') break;
    }

    $logins = [];
    foreach ($ultima as $s) {
        $v = (int) ($s['viewer_count'] ?? 0);
        if ($v < VITRINE_MIN_VIEWERS || $v > VITRINE_MAX_VIEWERS) continue;
        if (empty($s['user_login'])) continue;
        $logins[] = (string) $s['user_login'];
    }
    return $logins;
}

function vitrine_twitch(array $cfg, string $idioma, array $bloqueados): ?array
{
    $pool = vitrine_le('pool_twitch');
    $doDia = $pool && $pool['dia'] === date('Y-m-d', time()) && !empty($pool['valor']);

    if (!$doDia) {
        $logins = vitrine_pool_twitch((string) $cfg['categoria'], $idioma);
        if ($logins) vitrine_grava('pool_twitch', $logins);
    } else {
        $logins = $pool['valor'];
    }

    if (!$logins) return null;

    /* Um pedido só resolve os cem. Quem já desligou some da resposta, e é
       isso que impede o cartaz de mentir. */
    $vivos = vitrine_ao_vivo($logins, $bloqueados);
    if ($vivos) return vitrine_cartao($vivos[random_int(0, count($vivos) - 1)]);

    /* Todos do dia já desligaram: mostra um desligado mesmo, e amanhã a
       caminhada refaz a lista. */
    return vitrine_cartao_offline($logins[random_int(0, count($logins) - 1)], $bloqueados);
}

function vitrine_sortear(string $fatia, array $cfg, string $idioma, array $bloqueados): ?array
{
    if (($cfg['fixo'] ?? '') !== '') {
        $vivos = vitrine_ao_vivo([$cfg['fixo']], $bloqueados);
        return $vivos ? vitrine_cartao($vivos[0]) : vitrine_cartao_offline($cfg['fixo'], $bloqueados);
    }

    if ($fatia === 'usuario') return vitrine_do_site(false, $bloqueados);
    if ($fatia === 'pro')     return vitrine_do_site(true, $bloqueados);
    return vitrine_twitch($cfg, $idioma, $bloqueados);
}

/* ------------------------------------------------------------------ *
 *  Tópicos em alta
 * ------------------------------------------------------------------ */

/**
 * O que está rendendo audiência agora, num idioma.
 *
 * Sai da PRIMEIRA página de /streams — as cem maiores lives daquele idioma —
 * somando espectadores por categoria. Um pedido só, e é medida de verdade do
 * momento, não a lista global da Twitch (que é dominada por inglês e não diz
 * nada sobre o público brasileiro).
 */
function vitrine_altas(string $idioma, string $categoria, array $bloqueados): array
{
    $q = ['language' => ($idioma ?: 'pt'), 'first' => '100', 'type' => 'live'];

    if ($categoria !== '') {
        [$h, $g] = tw_helix_app('GET', '/games', ['name' => $categoria]);
        if ($h === 200 && !empty($g['data'][0]['id'])) $q['game_id'] = (string) $g['data'][0]['id'];
    }

    [$http, $r] = tw_helix_app('GET', '/streams', $q);
    if ($http !== 200 || empty($r['data'])) return ['topicos' => [], 'canais' => []];

    $porJogo = [];
    $canais = [];

    foreach ($r['data'] as $s) {
        $jogo = (string) ($s['game_name'] ?? '');
        if ($jogo !== '') {
            if (!isset($porJogo[$jogo])) $porJogo[$jogo] = ['nome' => $jogo, 'canais' => 0, 'espectadores' => 0];
            $porJogo[$jogo]['canais']++;
            $porJogo[$jogo]['espectadores'] += (int) ($s['viewer_count'] ?? 0);
        }
        if (count($canais) < 12 && vitrine_serve($s, $bloqueados)) {
            $canais[] = vitrine_cartao($s);
        }
    }

    usort($porJogo, fn($a, $b) => $b['espectadores'] <=> $a['espectadores']);

    return ['topicos' => array_slice(array_values($porJogo), 0, 8), 'canais' => $canais];
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

    $limpaLogin = fn($v) => strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) $v));

    if ($acao === 'config') {
        $cfg = vitrine_config();
        foreach (VITRINE_FATIAS as $f) {
            if (!isset($d[$f]) || !is_array($d[$f])) continue;
            $cfg[$f]['ligado']    = !empty($d[$f]['ligado']);
            $cfg[$f]['fixo']      = $limpaLogin($d[$f]['fixo'] ?? '');
            $cfg[$f]['categoria'] = mb_substr(trim((string) ($d[$f]['categoria'] ?? '')), 0, 80);
        }
        if (isset($d['idioma'])) {
            $cfg['idioma'] = preg_replace('/[^a-z-]/', '', strtolower((string) $d['idioma'])) ?: 'pt';
        }
        vitrine_grava('config', $cfg);

        /* Mudou a regra, o que estava guardado não vale mais. */
        foreach (VITRINE_FATIAS as $f) {
            db()->prepare('DELETE FROM vitrine WHERE chave = ?')->execute(['fatia_' . $f]);
        }
        db()->prepare('DELETE FROM vitrine WHERE chave IN (?, ?)')->execute(['pool_twitch', 'altas']);
        json_saida(['ok' => true, 'config' => $cfg]);
    }

    if ($acao === 'sortear') {
        $f = (string) ($d['fatia'] ?? '');
        if (!in_array($f, VITRINE_FATIAS, true)) json_saida(['erro' => 'Fatia desconhecida.'], 400);
        db()->prepare('DELETE FROM vitrine WHERE chave = ?')->execute(['fatia_' . $f]);
        /* Sortear de novo o da Twitch tem que refazer a caminhada também,
           senão ele só troca de nome dentro da mesma lista de hoje. */
        if ($f === 'twitch') db()->prepare('DELETE FROM vitrine WHERE chave = ?')->execute(['pool_twitch']);
        json_saida(['ok' => true]);
    }

    if ($acao === 'banir') {
        $login = $limpaLogin($d['login'] ?? '');
        if ($login === '') json_saida(['erro' => 'Falta o canal.'], 400);

        db()->prepare(
            'INSERT INTO vitrine_bloqueio (login, motivo) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)'
        )->execute([$login, mb_substr(trim((string) ($d['motivo'] ?? '')), 0, 160) ?: null]);

        /* Some AGORA, não amanhã. */
        foreach (VITRINE_FATIAS as $f) {
            $g = vitrine_le('fatia_' . $f);
            if (strtolower((string) ($g['valor']['login'] ?? '')) === $login) {
                db()->prepare('DELETE FROM vitrine WHERE chave = ?')->execute(['fatia_' . $f]);
            }
        }
        json_saida(['ok' => true]);
    }

    if ($acao === 'desbanir') {
        db()->prepare('DELETE FROM vitrine_bloqueio WHERE login = ?')->execute([$limpaLogin($d['login'] ?? '')]);
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
$idioma = (string) $cfg['idioma'];

/* O visitante pode filtrar os tópicos sem mexer na configuração de ninguém. */
$idiomaPedido = preg_replace('/[^a-z-]/', '', strtolower((string) ($_GET['idioma'] ?? ''))) ?: $idioma;
$catPedida    = mb_substr(trim((string) ($_GET['categoria'] ?? '')), 0, 80);

$fatias = [];
foreach (VITRINE_FATIAS as $f) {
    if (!$cfg[$f]['ligado']) continue;

    $g = vitrine_le('fatia_' . $f);
    $fresco = $g && $g['idade'] < VITRINE_FRESCOR;

    if ($fresco) {
        $cartao = $g['valor'];
    } else {
        try {
            $cartao = vitrine_sortear($f, $cfg[$f], $idioma, $bloqueados);
        } catch (Throwable $e) {
            $cartao = null;
        }

        /* GUARDA ATÉ O "NÃO ACHEI".

           Sem isto, uma fatia vazia refaz a busca a CADA visita da página
           inicial — e a fatia 'pro' consulta o acesso de cada conta do site
           pra montar a lista. Com quatro contas ninguém sente; com
           quatrocentas, a home cai sozinha. */
        vitrine_grava('fatia_' . $f, $cartao);
    }

    /* Banido depois de guardado não pode continuar aparecendo. */
    if ($cartao && in_array(strtolower((string) ($cartao['login'] ?? '')), $bloqueados, true)) {
        $cartao = null;
    }

    if ($cartao) $fatias[$f] = $cartao;
}

/* ----- tópicos em alta ----- */
/* A CHAVE PRECISA CABER EM VARCHAR(32).

   Nome de categoria é livre e comprido — "League of Legends: Wild Rift"
   sozinho estoura o campo. Fora do modo estrito o MySQL CORTA em silêncio, e
   aí duas categorias diferentes passariam a dividir a mesma linha de cache,
   uma servindo o resultado da outra. Resumir em md5 dá tamanho fixo. */
$chaveAltas = 'altas_' . substr(md5($idiomaPedido . '|' . mb_strtolower($catPedida)), 0, 20);
$ga = vitrine_le($chaveAltas);

if ($ga && $ga['idade'] < VITRINE_FRESCOR) {
    $altas = $ga['valor'];
} else {
    try {
        $altas = vitrine_altas($idiomaPedido, $catPedida, $bloqueados);
    } catch (Throwable $e) {
        $altas = $ga['valor'] ?? ['topicos' => [], 'canais' => []];
    }
    vitrine_grava($chaveAltas, $altas);
}

header('Cache-Control: public, max-age=120');

json_saida([
    'fatias' => $fatias,
    'altas'  => [
        'idioma'    => $idiomaPedido,
        'categoria' => $catPedida,
        'topicos'   => $altas['topicos'] ?? [],
        'canais'    => $altas['canais'] ?? [],
    ],
]);
