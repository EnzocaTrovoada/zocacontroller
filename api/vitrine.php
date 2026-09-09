<?php
/**
 * A vitrine da página inicial: a live pequena do dia.
 *
 * A ideia é simples e a execução tem um porém. A API da Twitch devolve as
 * lives ORDENADAS POR AUDIÊNCIA, da maior pra menor — exatamente ao contrário
 * do que a gente quer. Não existe "me dê uma live pequena": tem que caminhar
 * páginas pra baixo até chegar em quem tem poucos espectadores.
 *
 * Por isso a escolha acontece UMA VEZ POR DIA e fica guardada. Trinta e poucos
 * pedidos de uma vez, uma vez a cada vinte e quatro horas, e todo mundo que
 * abrir o site nesse dia lê do banco.
 *
 * Sem chave: é vitrine, é pra ser vista por quem ainda não entrou.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/twitch.php';

cors();

/* Quantas páginas descer antes de olhar. Sorteado pra que a vitrine não caia
   sempre na mesma faixa de audiência — e com teto, porque cada página é um
   pedido e ninguém precisa esperar cem deles. */
const VITRINE_MIN_PAGINAS = 12;
const VITRINE_MAX_PAGINAS = 34;

/* O que conta como "pequena". Zero espectador quase sempre é live recém-aberta
   sem ninguém pra ver ainda; acima de trinta a pessoa já não precisa da ajuda. */
const VITRINE_MIN_VIEWERS = 1;
const VITRINE_MAX_VIEWERS = 30;

function vitrine_bloqueados(): array
{
    try {
        return db()->query('SELECT login FROM vitrine_bloqueio')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Caminha a lista de lives em português até achar as pequenas.
 *
 * Devolve null quando não deu — e não dar certo é normal: a Twitch às vezes
 * responde devagar, o cursor acaba antes, ninguém pequeno está no ar. Nesse
 * caso a página inicial simplesmente não mostra a seção, que é bem melhor do
 * que mostrar erro.
 */
function vitrine_sortear(): ?array
{
    $bloqueados = array_map('strtolower', vitrine_bloqueados());
    $paginas = random_int(VITRINE_MIN_PAGINAS, VITRINE_MAX_PAGINAS);
    $cursor = '';
    $achadas = [];

    for ($i = 0; $i < $paginas; $i++) {
        $q = ['language' => 'pt', 'first' => '100', 'type' => 'live'];
        if ($cursor !== '') $q['after'] = $cursor;

        [$http, $r] = tw_helix_app('GET', '/streams', $q);
        if ($http !== 200 || empty($r['data'])) break;

        /* Só olho o conteúdo da ÚLTIMA página que eu ia ver de qualquer jeito:
           as de cima são audiência grande, que não é o público desta seção. */
        if ($i === $paginas - 1) {
            foreach ($r['data'] as $s) {
                $v = (int) ($s['viewer_count'] ?? 0);
                if ($v < VITRINE_MIN_VIEWERS || $v > VITRINE_MAX_VIEWERS) continue;
                /* Conteúdo adulto fora: esta live vai parar na página inicial
                   de um site que qualquer um abre, inclusive menor de idade. */
                if (!empty($s['is_mature'])) continue;
                if (in_array(strtolower((string) ($s['user_login'] ?? '')), $bloqueados, true)) continue;
                $achadas[] = $s;
            }
        }

        $cursor = (string) ($r['pagination']['cursor'] ?? '');
        if ($cursor === '') break;
    }

    if (!$achadas) return null;
    $s = $achadas[random_int(0, count($achadas) - 1)];

    /* A miniatura vem com {width} e {height} pra gente escolher. */
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

/* ------------------------------------------------------------------ *
 *  A resposta
 * ------------------------------------------------------------------ */

$st = db()->prepare('SELECT valor, atualizado_em FROM vitrine WHERE chave = ?');
$st->execute(['live_do_dia']);
$linha = $st->fetch();

$hoje = date('Y-m-d');
$valeAinda = $linha && substr((string) $linha['atualizado_em'], 0, 10) === $hoje;

if ($valeAinda) {
    $live = json_decode((string) $linha['valor'], true);
} else {
    /* Escolher pode demorar. Quem chegou primeiro paga a conta, mas se der
       errado a gente devolve a de ontem em vez de nada: live de ontem na
       vitrine é bem menos ruim do que um buraco na página inicial. */
    try {
        $live = vitrine_sortear();
    } catch (Throwable $e) {
        $live = null;
    }

    if ($live) {
        db()->prepare(
            'INSERT INTO vitrine (chave, valor, atualizado_em) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW()'
        )->execute(['live_do_dia', json_encode($live, JSON_UNESCAPED_UNICODE)]);
    } elseif ($linha) {
        $live = json_decode((string) $linha['valor'], true);
    }
}

/* Cinco minutos de cache na borda: a vitrine muda uma vez por dia, e cada
   visita da página inicial não precisa acordar o banco. */
header('Cache-Control: public, max-age=300');

json_saida(['live' => $live ?: null]);
