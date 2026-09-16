<?php
/**
 * O núcleo das conquistas.
 *
 * A CONQUISTA MORA NO BANCO; O QUE ELA MEDE MORA NO CÓDIGO. Nome, meta,
 * prêmio e se está ativa são editados no painel de admin (tabela
 * conquistas). O que se conta — posts, curtidas, overlays — é um arquivo em
 * api/medidores/, com o contrato em _MODELO.php. O painel escolhe um
 * medidor da lista; consulta nenhuma é escrita por ele.
 *
 * GANHAR E RECEBER SÃO DUAS COISAS. Ganhar é bater a meta, e fica gravado
 * na hora. Receber é o prêmio chegar — e um selo que ainda não foi
 * desenhado não tem como chegar. A conquista fica ganha e o prêmio é
 * tentado de novo a cada conferência, até entrar.
 *
 * SÓ DÁ, NUNCA TIRA. Conquista desativada some pra quem não tem; quem tem
 * continua com ela e com o prêmio.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notificacoes.php';

/* De quanto em quanto tempo uma pessoa é conferida de novo. Conferir custa
   uma contagem por medidor, e o painel pergunta a cada visita. */
const CONQ_INTERVALO = 600;

const CONQ_GRUPOS = ['comeco', 'live', 'feed', 'comunidade'];

/* ------------------------------------------------------------------ *
 *  Medidores
 * ------------------------------------------------------------------ */

/** Todos os medidores da pasta, pelo id. */
function conq_medidores(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (glob(__DIR__ . '/../medidores/*.php') ?: [] as $arq) {
        if (basename($arq)[0] === '_') continue;
        try {
            $m = require $arq;
        } catch (Throwable $e) {
            continue;
        }
        if (!is_array($m) || empty($m['id']) || empty($m['contar']) || !is_callable($m['contar'])) continue;
        if (!preg_match('/^[a-z0-9-]{2,40}$/', (string) $m['id'])) continue;
        $cache[(string) $m['id']] = $m;
    }
    ksort($cache);
    return $cache;
}

/**
 * Quanto a pessoa tem num medidor. Cada medidor é contado uma vez por
 * pessoa por requisição, mesmo que várias conquistas usem ele.
 */
function conq_medir(string $medidor, int $uid): int
{
    static $memoria = [];
    $k = $uid . ':' . $medidor;
    if (array_key_exists($k, $memoria)) return $memoria[$k];

    $m = conq_medidores()[$medidor] ?? null;
    if (!$m) return $memoria[$k] = 0;
    try {
        return $memoria[$k] = max(0, (int) ($m['contar'])($uid));
    } catch (Throwable $e) {
        return $memoria[$k] = 0;     /* tabela que ainda não existe: fica pra outra vez */
    }
}

function conq_unidade(string $medidor, int $n): string
{
    $u = conq_medidores()[$medidor]['unidade'] ?? ['', ''];
    return (string) ($n === 1 ? ($u[0] ?? '') : ($u[1] ?? ''));
}

/* ------------------------------------------------------------------ *
 *  As conquistas
 * ------------------------------------------------------------------ */

/**
 * As conquistas do banco, pelo id, na ordem da tela.
 * $todas inclui as desativadas — é o que conta pros prêmios de quem já tem.
 * $reler joga fora o que foi lido: o editor muda o banco no meio da requisição.
 */
function conq_lista(bool $todas = false, bool $reler = false): array
{
    static $cache = null;
    if ($reler) $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $st = db()->query('SELECT * FROM conquistas');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $cache[(string) $c['id']] = $c;
        } catch (Throwable $e) {
            $cache = [];    /* SQL 051 ainda não rodou */
        }

        uasort($cache, function ($a, $b) {
            $ga = array_search($a['grupo'], CONQ_GRUPOS, true);
            $gb = array_search($b['grupo'], CONQ_GRUPOS, true);
            return [$ga === false ? 9 : $ga, (int) $a['ordem'], (int) $a['meta'], (string) $a['nome']]
               <=> [$gb === false ? 9 : $gb, (int) $b['ordem'], (int) $b['meta'], (string) $b['nome']];
        });
    }

    return $todas ? $cache : array_filter($cache, fn($c) => (int) $c['ativa'] === 1);
}

/** Os prêmios da conquista, como lista. */
function conq_premios(array $c): array
{
    $p = [];
    if ((int) ($c['premio_vagas'] ?? 0) > 0) $p[] = ['tipo' => 'overlays', 'quantos' => (int) $c['premio_vagas']];
    if (!empty($c['premio_selo'])) $p[] = ['tipo' => 'selo', 'slug' => (string) $c['premio_selo']];
    if ((int) ($c['premio_pro'] ?? 0) > 0) $p[] = ['tipo' => 'pro', 'dias' => (int) $c['premio_pro']];
    return $p;
}

/** O prêmio dito pra quem vai ler. */
function conq_premio_texto(array $c): string
{
    $partes = [];
    foreach (conq_premios($c) as $p) {
        switch ($p['tipo']) {
            case 'overlays':
                $partes[] = $p['quantos'] . ($p['quantos'] === 1 ? ' vaga de overlay' : ' vagas de overlay');
                break;
            case 'selo':
                $partes[] = 'selo ' . conq_selo_nome($p['slug']);
                break;
            case 'pro':
                $partes[] = $p['dias'] . ($p['dias'] === 1 ? ' dia de Pro' : ' dias de Pro');
                break;
        }
    }
    return implode(' + ', $partes);
}

function conq_selo_nome(string $slug): string
{
    static $nomes = null;
    if ($nomes === null) {
        $nomes = [];
        try {
            foreach (db()->query('SELECT slug, nome FROM selos')->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $nomes[(string) $s['slug']] = (string) $s['nome'];
            }
        } catch (Throwable $e) {}
    }
    return $nomes[$slug] ?? ucfirst($slug);
}

/** O que a pessoa já ganhou: [id => ['ha' => segundos, 'premiado' => bool]]. */
function conq_ganhas(int $uid): array
{
    try {
        $st = db()->prepare('SELECT conquista, premiado, TIMESTAMPDIFF(SECOND, ganhou_em, NOW()) AS ha
                               FROM conquistas_usuario WHERE usuario_id = ?');
        $st->execute([$uid]);
    } catch (Throwable $e) {
        return [];
    }

    $saida = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $saida[(string) $g['conquista']] = ['ha' => (int) $g['ha'], 'premiado' => (bool) $g['premiado']];
    }
    return $saida;
}

/**
 * Vagas de overlay a mais que as conquistas ganhas dão — desativadas
 * inclusive. Qualquer erro vale zero: conquista quebrada não pode impedir
 * ninguém de usar o que já tinha.
 */
function conq_bonus_overlays(int $uid): int
{
    try {
        $total = 0;
        $todas = conq_lista(true);
        foreach (conq_ganhas($uid) as $id => $g) {
            if (isset($todas[$id])) $total += max(0, (int) $todas[$id]['premio_vagas']);
        }
        return min(500, $total);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Entrega o que precisa ser gravado: selo e dias de Pro.
 *
 * Devolve true quando não sobrou nada pendente.
 *
 * DIAS DE PRO SÓ NA PRIMEIRA VEZ. A entrega é tentada de novo enquanto
 * sobrar pendência — um selo que ainda não foi desenhado, por exemplo — e
 * repetir o selo não faz mal (ele entra uma vez só), mas repetir os dias
 * daria Pro infinito a cada conferência.
 */
function conq_entrega(int $uid, array $c, bool $primeiraVez): bool
{
    try {
        $tudo = true;

        foreach (conq_premios($c) as $p) {
            if ($p['tipo'] === 'selo') {
                $st = db()->prepare('SELECT id FROM selos WHERE slug = ?');
                $st->execute([$p['slug']]);
                $selo = (int) $st->fetchColumn();
                if (!$selo) { $tudo = false; continue; }

                db()->prepare('INSERT IGNORE INTO usuario_selos (usuario_id, selo_id) VALUES (?, ?)')
                    ->execute([$uid, $selo]);
            }

            if ($p['tipo'] === 'pro' && $primeiraVez) {
                /* SOMA, NÃO SUBSTITUI: quem já tinha cortesia fica com ela
                   mais os dias, e nunca com menos do que já tinha. */
                db()->prepare(
                    'UPDATE usuarios
                        SET cortesia_ate = DATE_ADD(GREATEST(COALESCE(cortesia_ate, NOW()), NOW()), INTERVAL ? DAY)
                      WHERE id = ?'
                )->execute([max(1, min(365, $p['dias'])), $uid]);
            }
        }

        return $tudo;
    } catch (Throwable $e) {
        return false;   /* coluna ou tabela que ainda não existe: tenta de novo depois */
    }
}

/**
 * Confere uma pessoa: quem bateu meta ganha, e prêmio pendente é tentado.
 *
 * Devolve os ids ganhos AGORA, pra tela poder comemorar.
 */
function conq_confere(int $uid, int $intervalo = CONQ_INTERVALO): array
{
    try {
        /* A marca de hora é tomada antes de conferir, e só por quem chegar
           primeiro: duas abas abertas ao mesmo tempo não conferem em dobro. */
        $vez = db()->prepare(
            'UPDATE usuarios SET conquistas_em = NOW()
              WHERE id = ? AND (conquistas_em IS NULL
                                OR TIMESTAMPDIFF(SECOND, conquistas_em, NOW()) >= ?)'
        );
        $vez->execute([$uid, $intervalo]);
        if ($vez->rowCount() === 0) return [];
    } catch (Throwable $e) {
        return [];      /* SQL 049 ainda não rodou */
    }

    $ganhas = conq_ganhas($uid);
    $novas = [];

    /* Prêmio pendente é tentado em qualquer conquista ganha, ativa ou não. */
    foreach (conq_lista(true) as $id => $c) {
        if (!isset($ganhas[$id]) || $ganhas[$id]['premiado']) continue;
        if (conq_entrega($uid, $c, false)) {
            db()->prepare('UPDATE conquistas_usuario SET premiado = 1 WHERE usuario_id = ? AND conquista = ?')
                ->execute([$uid, $id]);
        }
    }

    /* Ganhar, só nas ativas. */
    foreach (conq_lista() as $id => $c) {
        if (isset($ganhas[$id])) continue;
        if (conq_medir((string) $c['medidor'], $uid) < max(1, (int) $c['meta'])) continue;

        $ins = db()->prepare('INSERT IGNORE INTO conquistas_usuario (usuario_id, conquista) VALUES (?, ?)');
        $ins->execute([$uid, $id]);
        if ($ins->rowCount() === 0) continue;       /* outra requisição chegou antes */

        if (conq_entrega($uid, $c, true)) {
            db()->prepare('UPDATE conquistas_usuario SET premiado = 1 WHERE usuario_id = ? AND conquista = ?')
                ->execute([$uid, $id]);
        }

        $premio = conq_premio_texto($c);
        notifica($uid, 'conquista', '🏆 Conquista: ' . (string) $c['nome']
            . ($premio !== '' ? ' — você ganhou ' . $premio : ''), '#/conquistas', null, 'conq:' . $id);
        $novas[] = $id;
    }

    /* A soma vai pra coluna que o plano lê. Refeita a cada conferência:
       mudar o prêmio de uma conquista chega em quem já tem na próxima
       visita. */
    try {
        db()->prepare('UPDATE usuarios SET vagas_conquista = ? WHERE id = ?')
            ->execute([conq_bonus_overlays($uid), $uid]);
    } catch (Throwable $e) { /* coluna nova */ }

    return $novas;
}

/** Tudo que a tela da pessoa precisa, sem conferir nada. */
function conq_estado(int $uid): array
{
    $ganhas = conq_ganhas($uid);
    $saida = [];

    /* As ganhas aparecem mesmo desativadas: foi conquistado, fica no perfil. */
    foreach (conq_lista(true) as $id => $c) {
        $ganhou = isset($ganhas[$id]);
        if (!$ganhou && ((int) $c['ativa'] !== 1 || (int) $c['oculta'] === 1)) continue;

        $meta = max(1, (int) $c['meta']);
        $tem = $ganhou ? $meta : min($meta, conq_medir((string) $c['medidor'], $uid));

        $saida[] = [
            'id'        => $id,
            'nome'      => (string) $c['nome'],
            'descricao' => (string) $c['descricao'],
            'icone'     => (string) $c['icone'],
            'grupo'     => (string) $c['grupo'],
            'meta'      => $meta,
            'tem'       => $tem,
            'unidade'   => conq_unidade((string) $c['medidor'], $meta),
            'ganhou'    => $ganhou,
            'ha'        => $ganhou ? $ganhas[$id]['ha'] : null,
            'pendente'  => $ganhou && !$ganhas[$id]['premiado'],
            'premio'    => conq_premio_texto($c),
        ];
    }

    return $saida;
}
