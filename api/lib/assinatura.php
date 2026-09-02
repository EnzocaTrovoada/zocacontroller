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
function recursos_do_plano(string $plano): array
{
    if ($plano === 'pro' || $plano === 'pro_ano') {
        return [
            'marca_dagua'     => false,
            'pokebot'         => true,
            'multiplataforma' => true,
            'temas'           => 'todos',
            'perfis_max'      => 20,
        ];
    }

    return [
        'marca_dagua'     => true,
        'pokebot'         => false,
        'multiplataforma' => false,
        'temas'           => 'basicos',
        'perfis_max'      => 2,
    ];
}
