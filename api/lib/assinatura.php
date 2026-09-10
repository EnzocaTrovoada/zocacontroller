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

/**
 * A cobrança já abriu?
 *
 * Enquanto não abriu, NINGUÉM pode pagar — e limitar quem não tem como pagar
 * é só quebrar o site de graça. Por isso esta pergunta vem antes de qualquer
 * restrição: com a cobrança fechada, todo mundo é tratado como Pro.
 *
 * Isso vale pra tudo de uma vez: marca d'água, teto de overlays, comandos.
 * Um lugar só decide, então virar a chave no config liga tudo junto e nada
 * fica esquecido restringindo por engano.
 */
function cobranca_aberta(): bool
{
    return !empty(cfg()['mercadopago']['ligado']);
}

/**
 * Este usuário pode usar este recurso agora?
 *
 * A porta única. Espalhar "if plano ==" pelo código é como um recurso acaba
 * bloqueado num lugar e liberado noutro — e o lugar esquecido é sempre o que
 * alguém encontra.
 */
function recurso_liberado(int $usuario_id, string $chave): bool
{
    $a = acesso_do_usuario($usuario_id);
    $r = recursos_do_usuario($usuario_id, $a['ativo'] ? $a['plano'] : 'gratis');
    return !empty($r[$chave]);
}

/** Recursos por plano num lugar só. Não espalhar "if plano ==" pelo código. */
/**
 * O que este usuario pode, com as excecoes dele por cima do plano.
 *
 * O plano continua sendo a regra; a sobrescrita fica visivel como excecao, e
 * NULL quer dizer "vale o do plano". Sem essa separacao, dar um extra pra
 * alguem viraria uma linha de plano nova pra cada favor feito.
 */
/**
 * Este usuário entrou antes da cobrança existir?
 *
 * Quem testou o site enquanto ele era grátis não pode acordar um dia com
 * marca d'água na live e metade dos overlays travados porque a gente resolveu
 * cobrar. Eles ficam com tudo, e a marca é ligada à mão no painel de
 * administração se um dia fizer sentido.
 *
 * Coluna nova em vez de assinatura de cortesia de propósito: assim o
 * relatório de cobrança nunca mistura cortesia com dinheiro de verdade.
 */
function usuario_beta(int $usuario_id): bool
{
    try {
        $st = db()->prepare('SELECT beta FROM usuarios WHERE id = ?');
        $st->execute([$usuario_id]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        /* Coluna nova: quem ainda não rodou o SQL não pode ver o site quebrar.
           Falhar pro lado de NÃO-beta seria o contrário do que este código
           existe pra fazer, mas falhar pro lado de beta liberaria tudo pra
           todo mundo — então o silêncio aqui só vale enquanto a cobrança
           estiver fechada, e nesse caso já está tudo liberado mesmo. */
        return false;
    }
}

/**
 * Pro dado à mão pelo administrador, com prazo.
 *
 * Diferente de beta: beta é quem chegou antes da cobrança existir e não
 * perde nada nunca. Cortesia é "eu te dou Pro até tal dia" — parceria,
 * compensação por um problema, sorteio. Separados porque a pergunta "por
 * que essa pessoa tem acesso?" precisa ter resposta seis meses depois.
 */
function usuario_cortesia(int $usuario_id): bool
{
    try {
        $st = db()->prepare('SELECT cortesia_ate FROM usuarios WHERE id = ?');
        $st->execute([$usuario_id]);
        $ate = $st->fetchColumn();
        return $ate !== null && $ate !== false && strtotime((string) $ate) > time();
    } catch (Throwable $e) {
        return false;   /* coluna nova */
    }
}

function recursos_do_usuario(int $usuario_id, string $plano): array
{
    /* Três portas pro mesmo lugar: a cobrança ainda não abriu, a pessoa
       estava aqui antes dela abrir, ou alguém deu Pro pra ela. */
    $comoPro = !cobranca_aberta() || usuario_beta($usuario_id) || usuario_cortesia($usuario_id);
    $r = recursos_do_plano($comoPro ? 'pro' : $plano);

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

/* "Ilimitado" é um número grande, não um caso especial.
   Um null ou um -1 aqui obrigaria toda comparação de teto a aprender a
   exceção, e a que esquecesse trataria ilimitado como zero. */
const PERFIS_ILIMITADO = 9999;

function recursos_do_plano(string $plano): array
{
    /* VITALÍCIO É O MESMO PRO, PAGO UMA VEZ SÓ.

       Nenhum recurso é exclusivo dele. Isso é escolha de produto e simplifica
       o código todo: existem dois níveis de acesso, não três, e nenhuma tela
       precisa explicar por que uma coisa aparece num plano pago e não no
       outro. O que o vitalício compra é não pagar de novo. */
    if ($plano === 'pro' || $plano === 'pro_ano' || $plano === 'vitalicio') {
        return [
            'marca_dagua'     => false,
            'pokebot'         => true,
            'multiplataforma' => true,
            'temas'           => 'todos',
            'perfis_max'      => PERFIS_ILIMITADO,
            'musica_chat'     => true,
            'oque_streamar'   => true,
            /* Os três abaixo não são checados por código nenhum: são promessa
               de atendimento, e quem cumpre é o Enzo. Ficam aqui pra que a
               lista do painel e o que o servidor sabe sejam a mesma coisa. */
            'beta'            => true,
            'sugestoes'       => true,
            'suporte'         => 'pessoal',
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
        'musica_chat'     => false,
        'oque_streamar'   => false,
        'beta'            => false,
        'sugestoes'       => false,
        'suporte'         => 'comum',
    ];
}
