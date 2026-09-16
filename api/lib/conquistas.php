<?php
/**
 * O núcleo das conquistas. Não conhece conquista nenhuma.
 *
 * Cada conquista é um arquivo em api/conquistas/, achado sozinho por este
 * aqui. O contrato está inteiro em api/conquistas/_MODELO.php.
 *
 * GANHAR E RECEBER SÃO DUAS COISAS. Ganhar é bater a meta, e fica gravado
 * na hora. Receber é o prêmio chegar — e um selo que ainda não foi
 * desenhado não tem como chegar. A conquista fica ganha e o prêmio é
 * tentado de novo a cada conferência, até entrar.
 *
 * As vagas de overlay não são entregues uma a uma: a soma delas é refeita a
 * cada conferência e guardada em usuarios.vagas_conquista, que é o que o
 * plano lê. Mudar o prêmio de uma conquista chega em quem já tem na próxima
 * visita, sem migração.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notificacoes.php';

/* De quanto em quanto tempo uma pessoa é conferida de novo. Conferir custa
   uma contagem por conquista, e o painel pergunta a cada visita. */
const CONQ_INTERVALO = 600;

const CONQ_GRUPOS = ['comeco', 'live', 'feed', 'comunidade'];

/** Todas as conquistas que existem na pasta, pelo id. */
function conq_lista(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (glob(__DIR__ . '/../conquistas/*.php') ?: [] as $arq) {
        if (basename($arq)[0] === '_') continue;
        try {
            $c = require $arq;
        } catch (Throwable $e) {
            continue;
        }
        if (!is_array($c) || empty($c['id']) || empty($c['progresso']) || !is_callable($c['progresso'])) continue;
        if (!preg_match('/^[a-z0-9-]{2,40}$/', (string) $c['id'])) continue;
        $cache[(string) $c['id']] = $c;
    }

    uasort($cache, function ($a, $b) {
        $ga = array_search($a['grupo'] ?? 'comeco', CONQ_GRUPOS, true);
        $gb = array_search($b['grupo'] ?? 'comeco', CONQ_GRUPOS, true);
        return [$ga === false ? 9 : $ga, (int) ($a['meta'] ?? 1), (int) ($a['ordem'] ?? 50)]
           <=> [$gb === false ? 9 : $gb, (int) ($b['meta'] ?? 1), (int) ($b['ordem'] ?? 50)];
    });
    return $cache;
}

/** O prêmio sempre como lista, mesmo quando o arquivo escreveu um só. */
function conq_premios(array $c): array
{
    $p = $c['premio'] ?? [];
    if (!is_array($p) || !$p) return [];
    return isset($p['tipo']) ? [$p] : array_values(array_filter($p, 'is_array'));
}

/** O prêmio dito pra quem vai ler. */
function conq_premio_texto(array $c): string
{
    $partes = [];
    foreach (conq_premios($c) as $p) {
        switch ($p['tipo'] ?? '') {
            case 'overlays':
                $n = max(1, (int) ($p['quantos'] ?? 1));
                $partes[] = $n . ($n === 1 ? ' vaga de overlay' : ' vagas de overlay');
                break;
            case 'selo':
                $partes[] = 'selo ' . conq_selo_nome((string) ($p['slug'] ?? ''));
                break;
            case 'pro':
                $d = max(1, (int) ($p['dias'] ?? 1));
                $partes[] = $d . ($d === 1 ? ' dia de Pro' : ' dias de Pro');
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

/** O que a pessoa já ganhou: [id => ['em' => ..., 'premiado' => bool]]. */
function conq_ganhas(int $uid): array
{
    try {
        $st = db()->prepare('SELECT conquista, ganhou_em, premiado,
                                    TIMESTAMPDIFF(SECOND, ganhou_em, NOW()) AS ha
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
 * Vagas de overlay a mais que as conquistas deram.
 *
 * Soma feita na conferência e gravada numa coluna. Qualquer erro aqui vale
 * zero: conquista quebrada não pode impedir ninguém de usar o que já tinha.
 */
function conq_bonus_overlays(int $uid): int
{
    try {
        $total = 0;
        $todas = conq_lista();
        foreach (conq_ganhas($uid) as $id => $g) {
            if (!isset($todas[$id])) continue;
            foreach (conq_premios($todas[$id]) as $p) {
                if (($p['tipo'] ?? '') === 'overlays') $total += max(0, (int) ($p['quantos'] ?? 0));
            }
        }
        return min(500, $total);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Entrega o que precisa ser gravado: selo e dias de Pro.
 *
 * Devolve true quando não sobrou nada pendente. As vagas de overlay não
 * passam por aqui — elas são somadas, não entregues.
 *
 * DIAS DE PRO SÓ NA PRIMEIRA VEZ. A entrega é tentada de novo enquanto
 * sobrar pendência — um selo que ainda não foi desenhado, por exemplo — e
 * repetir o selo não faz mal (ele entra uma vez só), mas repetir os dias
 * daria Pro infinito a cada conferência. Por isso a nova tentativa pula
 * tudo que não pode ser repetido.
 */
function conq_entrega(int $uid, array $c, bool $primeiraVez): bool
{
    try {
        return conq_entrega_ja($uid, $c, $primeiraVez);
    } catch (Throwable $e) {
        return false;   /* coluna ou tabela que ainda não existe: tenta de novo depois */
    }
}

function conq_entrega_ja(int $uid, array $c, bool $primeiraVez): bool
{
    $tudo = true;

    foreach (conq_premios($c) as $p) {
        $tipo = (string) ($p['tipo'] ?? '');

        if ($tipo === 'selo') {
            $st = db()->prepare('SELECT id FROM selos WHERE slug = ?');
            $st->execute([(string) ($p['slug'] ?? '')]);
            $selo = (int) $st->fetchColumn();
            if (!$selo) { $tudo = false; continue; }     /* selo ainda não existe */

            db()->prepare('INSERT IGNORE INTO usuario_selos (usuario_id, selo_id) VALUES (?, ?)')
                ->execute([$uid, $selo]);
        }

        if ($tipo === 'pro' && $primeiraVez) {
            /* SOMA, NÃO SUBSTITUI. Quem já tinha cortesia até o fim do mês e
               ganha 7 dias fica com o fim do mês mais 7 — e nunca com menos
               do que já tinha. */
            $dias = max(1, min(365, (int) ($p['dias'] ?? 1)));
            db()->prepare(
                'UPDATE usuarios
                    SET cortesia_ate = DATE_ADD(GREATEST(COALESCE(cortesia_ate, NOW()), NOW()), INTERVAL ? DAY)
                  WHERE id = ?'
            )->execute([$dias, $uid]);
        }
    }

    return $tudo;
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

    foreach (conq_lista() as $id => $c) {
        if (isset($ganhas[$id])) {
            if (!$ganhas[$id]['premiado'] && conq_entrega($uid, $c, false)) {
                db()->prepare('UPDATE conquistas_usuario SET premiado = 1 WHERE usuario_id = ? AND conquista = ?')
                    ->execute([$uid, $id]);
            }
            continue;
        }

        try {
            $tem = (int) ($c['progresso'])($uid);
        } catch (Throwable $e) {
            continue;   /* tabela que ainda não existe: fica pra outra vez */
        }
        if ($tem < max(1, (int) ($c['meta'] ?? 1))) continue;

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

    /* A soma vai pra coluna que o plano lê. Refeita a cada conferência, e
       não só quando alguém ganha: assim, mudar o prêmio de uma conquista no
       arquivo chega em quem já tinha ela na próxima visita. */
    try {
        db()->prepare('UPDATE usuarios SET vagas_conquista = ? WHERE id = ?')
            ->execute([conq_bonus_overlays($uid), $uid]);
    } catch (Throwable $e) { /* coluna nova */ }

    return $novas;
}

/** Tudo que a tela precisa, sem conferir nada. */
function conq_estado(int $uid): array
{
    $ganhas = conq_ganhas($uid);
    $saida = [];

    foreach (conq_lista() as $id => $c) {
        $ganhou = isset($ganhas[$id]);
        if (!empty($c['oculta']) && !$ganhou) continue;

        $meta = max(1, (int) ($c['meta'] ?? 1));
        $tem = $meta;
        if (!$ganhou) {
            try { $tem = min($meta, (int) ($c['progresso'])($uid)); } catch (Throwable $e) { $tem = 0; }
        }

        $saida[] = [
            'id'        => $id,
            'nome'      => (string) $c['nome'],
            'descricao' => (string) ($c['descricao'] ?? ''),
            'icone'     => (string) ($c['icone'] ?? '🏆'),
            'grupo'     => (string) ($c['grupo'] ?? 'comeco'),
            'meta'      => $meta,
            'tem'       => max(0, $tem),
            'ganhou'    => $ganhou,
            'ha'        => $ganhou ? $ganhas[$id]['ha'] : null,
            'pendente'  => $ganhou && !$ganhas[$id]['premiado'],
            'premio'    => conq_premio_texto($c),
        ];
    }

    return $saida;
}
