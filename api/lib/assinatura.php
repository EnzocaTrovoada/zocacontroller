<?php
require_once __DIR__ . '/db.php';

/**
 * Estado de acesso do usuário. Esta é a ÚNICA fonte da verdade —
 * o overlay e o painel nunca decidem sozinhos o que está liberado.
 */
function acesso_do_usuario(int $usuario_id): array
{
    $st = db()->prepare(
        'SELECT a.status, a.valido_ate, p.slug AS plano
           FROM assinaturas a
           JOIN planos p ON p.id = a.plano_id
          WHERE a.usuario_id = ?
          ORDER BY a.valido_ate DESC
          LIMIT 1'
    );
    $st->execute([$usuario_id]);
    $a = $st->fetch();

    if (!$a || $a['valido_ate'] === null) {
        return ['plano' => 'gratis', 'ativo' => false, 'cortesia' => false];
    }

    $agora    = time();
    $ate      = strtotime($a['valido_ate']);
    $cortesia = cfg()['grace_dias'] * 86400;

    // Cortesia: ninguém perde recurso no meio de uma live por atraso de cobrança.
    return [
        'plano'    => $a['plano'],
        'ativo'    => ($ate + $cortesia) > $agora,
        'cortesia' => $ate < $agora && ($ate + $cortesia) > $agora,
    ];
}

/** Recursos por plano num lugar só. Não espalhar "if plano ==" pelo código. */
/**
 * O que este usuario pode, com as excecoes dele por cima do plano.
 *
 * O plano continua sendo a regra; a sobrescrita fica visivel como excecao, e
 * NULL quer dizer "vale o do plano". Sem essa separacao, dar um extra pra
 * alguem viraria uma linha de plano nova pra cada favor feito.
 */
function recursos_do_usuario(int $usuario_id, string $plano): array
{
    $r = recursos_do_plano($plano);

    $st = db()->prepare('SELECT perfis_max, recursos FROM usuarios WHERE id = ?');
    $st->execute([$usuario_id]);
    $u = $st->fetch();
    if (!$u) return $r;

    if ($u['perfis_max'] !== null) $r['perfis_max'] = (int) $u['perfis_max'];

    if (!empty($u['recursos'])) {
        $extra = json_decode((string) $u['recursos'], true);
        /* So chaves que o plano ja conhece: recurso inventado no banco nao
           pode virar recurso de verdade sem passar pelo codigo. */
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                if (array_key_exists($k, $r)) $r[$k] = $v;
            }
        }
    }
    return $r;
}

function recursos_do_plano(string $plano): array
{
    if ($plano === 'pro' || $plano === 'pro_ano') {
        return [
            'marca_dagua'     => false,
            'pokebot'         => true,
            'multiplataforma' => true,
            'temas'           => 'todos',
            'perfis_max'      => 50,
        ];
    }

    return [
        'marca_dagua'     => true,
        'pokebot'         => false,
        'multiplataforma' => false,
        'temas'           => 'basicos',
        /* Dois era pouco demais: existem NOVE tipos de overlay, e quem chega
           quer experimentar antes de decidir. O teto existe pra impedir abuso,
           não pra impedir uso — e a hospedagem está em 5% com tudo junto. */
        'perfis_max'      => 8,
    ];
}
