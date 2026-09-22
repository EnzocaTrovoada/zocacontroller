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
 * O teto deste usuário pra uma coisa contável.
 *
 * É POR AQUI QUE TODA TRAVA PASSA. Cada endereço tinha o próprio número
 * cravado no código — 100 comandos aqui, 40 cenas ali — e mudar o plano
 * exigia caçar todos. Agora o número mora na tabela de cima, e quem não
 * conhece a chave recebe o padrão que veio junto.
 */
function limite(int $usuario_id, string $chave, int $padrao): int
{
    $a = acesso_do_usuario($usuario_id);
    $r = recursos_do_usuario($usuario_id, $a['ativo'] ? $a['plano'] : 'gratis');
    return isset($r[$chave]) ? max(0, (int) $r[$chave]) : $padrao;
}

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

    /* SELECT *: a coluna das vagas de conquista chegou depois, e este é o
       caminho que todo overlay no OBS percorre. Pedir ela pelo nome
       derrubaria todos os overlays de quem ainda não rodou o SQL 049. */
    $st = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$usuario_id]);
    $u = $st->fetch();
    if (!$u) return $r;

    if ($u['perfis_max'] !== null) $r['perfis_max'] = (int) $u['perfis_max'];

    /* As vagas que as conquistas deram somam por cima de tudo — do plano e
       de um teto dado à mão. Conquista só dá: nunca desce o número.

       Vem de uma coluna, e não da soma das conquistas: este caminho roda a
       cada consulta de cada overlay no OBS, e somar ali carregaria todos os
       arquivos de conquista toda vez. Quem mantém a coluna em dia é a
       conferência das conquistas. */
    $extra = max(0, (int) ($u['vagas_conquista'] ?? 0));
    if ($extra && $r['perfis_max'] < PERFIS_ILIMITADO) {
        $r['perfis_max'] = min(PERFIS_ILIMITADO, $r['perfis_max'] + $extra);
    }

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
            'pokebot'         => true,
            'multiplataforma' => true,
            'temas'           => 'todos',
            'perfis_max'      => PERFIS_ILIMITADO,
            'musica_chat'     => true,
            'oque_streamar'   => true,
            'selo_pro'        => true,
            'cor_propria'     => true,
            'anuncios'        => true,
            'bot_nuvem'       => true,
            'comandos_max'    => 200,
            'gatilhos_max'    => 20,
            'recados_max'     => 10,
            'luzes_max'       => 40,
            'sons_max'        => 30,
            'imagens_max'     => 150,
            'mods_max'        => 30,
            'backups_max'     => 8,
            'backup_horas'    => 20,
            'vitrine_peso'    => 3,
            /* Os três abaixo não são checados por código nenhum: são promessa
               de atendimento, e quem cumpre é o Enzo. Ficam aqui pra que a
               lista do painel e o que o servidor sabe sejam a mesma coisa. */
            'beta'            => true,
            'sugestoes'       => true,
            'suporte'         => 'pessoal',
        ];
    }

    return [
        'pokebot'         => false,
        'multiplataforma' => false,
        'temas'           => 'basicos',
        /* NÃO EXISTE MARCA D'ÁGUA EM PLANO NENHUM.

           Foi tirada de propósito: overlay com marca é overlay que a pessoa
           não usa, e um site que ninguém usa de graça não tem pra quem
           vender depois. O que separa grátis de pago é o TETO de overlays e
           os recursos — não um carimbo em cima da transmissão de quem está
           começando.

           Dois era pouco demais: existem dez tipos de overlay, e quem chega
           quer experimentar antes de decidir. */
        'perfis_max'      => 8,
        'musica_chat'     => false,
        'oque_streamar'   => false,

        /* O QUE SEPARA GRÁTIS DE PAGO, EM NÚMERO.

           A régua: fecha o que CUSTA (disco, assinatura de EventSub por
           canal, chamada de API) e o que é de quem já vive disso. Fica
           aberto o que traz gente — importar de outro bot, os comandos que
           a pessoa vai usar de verdade, os overlays pra experimentar.

           Nenhum destes APAGA nada. Quem caiu do Pro com 56 comandos
           continua com os 56 funcionando; só não cria o 57 até apagar. Foi
           escolha: apagar o trabalho de quem parou de pagar é como se
           ganha estorno e print no Twitter. */
        'selo_pro'        => false,
        'cor_propria'     => false,
        'anuncios'        => false,
        'bot_nuvem'       => false,
        'comandos_max'    => 30,
        'gatilhos_max'    => 5,
        'recados_max'     => 3,
        'luzes_max'       => 10,
        'sons_max'        => 6,
        'imagens_max'     => 20,
        'mods_max'        => 3,
        'backups_max'     => 3,
        /* Um por semana contra um por dia: o backup é disco nosso, e disco
           é a conta que cresce com gente usando. */
        'backup_horas'    => 168,
        'vitrine_peso'    => 1,
        'beta'            => false,
        'sugestoes'       => false,
        'suporte'         => 'comum',
    ];
}
