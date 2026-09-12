<?php
/**
 * Quantos seguidores (ou subs) o canal tem agora.
 *
 * É o que faz a meta se encher sozinha: ninguém digita o número, e ele não
 * envelhece. O overlay não fala com a Twitch — ele pergunta pra cá, e aqui a
 * resposta fica guardada por um minuto.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/twitch.php';
require_once __DIR__ . '/subathon-somar.php';

const CONTAGEM_FONTES = ['seguidores', 'subs', 'viewers'];

/* O QUE CADA PLATAFORMA SABE RESPONDER.
   Isto nao e opiniao: e o que a API oficial de cada uma expoe hoje.

   Kick nao tem contagem de seguidores em endpoint nenhum — da pra somar os
   webhooks de follow, mas sem evento de unfollow e sem valor absoluto pra
   re-sincronizar esse numero so sobe e nunca corrige. Contador que mente
   devagar e pior do que contador nenhum.

   YouTube nao tem "seguidor" nem "espectador ao vivo" por aqui; tem inscrito,
   e ARREDONDADO em tres algarismos significativos, inclusive pro dono do
   canal. Acima de mil ele anda de dez em dez; acima de cem mil, de mil em
   mil. Quem escolhe essa meta precisa saber disso na hora de escolher. */
const CONTAGEM_PLATAFORMAS = [
    'twitch'  => ['seguidores', 'subs', 'viewers'],
    'youtube' => ['subs'],
    'kick'    => ['subs', 'viewers'],
];

/** A chave que vai pro banco: a Twitch fica sem prefixo, pelo que ja existe. */
function contagem_chave(string $plataforma, string $fonte): string
{
    return $plataforma === 'twitch' ? $fonte : $plataforma . ':' . $fonte;
}

function contagem_vale(string $plataforma, string $fonte): bool
{
    return isset(CONTAGEM_PLATAFORMAS[$plataforma])
        && in_array($fonte, CONTAGEM_PLATAFORMAS[$plataforma], true);
}

/**
 * Devolve o número, ou null se nunca deu pra saber.
 *
 * NUNCA devolve zero por causa de falha: um overlay que zera no meio da live
 * porque a Twitch tossiu é pior do que um overlay parado no número de ontem.
 */
/* O que cada meta diz quando bate, e que tipo de evento ela vira. */
const META_FONTES = [
    'viewers'    => ['tipo' => 'viewers',  'quem' => 'a galera', 'sufixo' => ' assistindo'],
    'seguidores' => ['tipo' => 'metaseg',  'quem' => 'o canal',  'sufixo' => ' seguidores'],
    'subs'       => ['tipo' => 'metasubs', 'quem' => 'o canal',  'sufixo' => ' subs'],
];

/**
 * Bateu o alvo, soma tempo no subathon e o alvo sobe sozinho.
 *
 * O alvo mora no banco e nao na memoria justamente pra disparar UMA vez por
 * travessia. Se ficasse so na tela, cada consulta do overlay somaria tempo de
 * novo enquanto o numero estivesse acima do alvo — e uma noite boa viraria
 * tempo infinito em minutos.
 *
 * Serve pras tres fontes. Antes era so viewers, e as colunas seguem o mesmo
 * nome: <fonte>_alvo, <fonte>_seg, <fonte>_passo.
 */
function meta_bateu(int $usuario_id, string $fonte, int $agora): void
{
    if (!isset(META_FONTES[$fonte])) return;
    $spec = META_FONTES[$fonte];

    /* Nome de coluna nao vai em placeholder, entao ele NAO pode vir de fora:
       o isset acima e o que garante que $fonte e uma das tres escritas aqui
       dentro, e nao um texto qualquer que chegou pela requisicao. */
    $alvo  = $fonte . '_alvo';
    $seg   = $fonte . '_seg';
    $passo = $fonte . '_passo';

    $st = db()->prepare(
        "SELECT $alvo AS alvo, $seg AS seg, $passo AS passo FROM subathon
          WHERE usuario_id = ? AND ligado = 1"
    );
    $st->execute([$usuario_id]);
    $c = $st->fetch();
    if (!$c || !$c['alvo'] || !$c['seg']) return;
    if ($agora < (int) $c['alvo']) return;

    /* PASSO ZERO QUER DIZER "DISPARA UMA VEZ E ACABOU".

       Antes isto era GREATEST(passo, 1), o que forcava subir de um em um
       mesmo com o passo zerado: um canal de 1004 seguidores com alvo em 1000
       via o alvo virar 1001, 1002, 1003... uma consulta por vez, ate alcancar.
       Quem deixa o passo vazio quer uma meta unica, nao uma escada.

       Zerar o alvo depois de bater e explicito: a propria tela ja diz que
       alvo 0 e meta desligada, entao a pessoa VE que aquela acabou. */
    $novo = ((int) $c['passo'] > 0) ? (int) $c['alvo'] + (int) $c['passo'] : 0;

    /* O UPDATE condicional E a trava: quem conseguir mudar a linha e quem
       soma. Duas consultas ao mesmo tempo, so uma passa. */
    $sobe = db()->prepare(
        "UPDATE subathon SET $alvo = ? WHERE usuario_id = ? AND $alvo = ?"
    );
    $sobe->execute([$novo, $usuario_id, (int) $c['alvo']]);
    if (!$sobe->rowCount()) return;

    subathon_somar($usuario_id, [
        'tipo'    => $spec['tipo'],
        'chave'   => $fonte . '-' . $usuario_id . '-' . $c['alvo'],
        'quem'    => $spec['quem'],
        'detalhe' => $c['alvo'] . $spec['sufixo'],
        'segundos_fixos' => (int) $c['seg'],
    ]);
}

/** O nome antigo continua valendo: o config-overlay ja chamava assim. */
function viewers_meta(int $usuario_id, int $agora): void
{
    meta_bateu($usuario_id, 'viewers', $agora);
}

function contagem(int $usuario_id, string $fonte, int $maxIdade = 60, string $plataforma = 'twitch'): ?int
{
    if (!contagem_vale($plataforma, $fonte)) return null;
    $chave = contagem_chave($plataforma, $fonte);

    $st = db()->prepare(
        'SELECT valor, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade
           FROM contagens WHERE usuario_id = ? AND fonte = ?'
    );
    $st->execute([$usuario_id, $chave]);
    $linha = $st->fetch();

    if ($linha && (int) $linha['idade'] < $maxIdade) {
        return (int) $linha['valor'];
    }
    $anterior = $linha ? (int) $linha['valor'] : null;

    try {
        if ($plataforma === 'youtube') {
            require_once __DIR__ . '/youtube.php';
            $corpo = ['total' => yt_inscritos($usuario_id)];
            $ok = true;
            $http = 200;
        } elseif ($plataforma === 'kick') {
            require_once __DIR__ . '/kick.php';
            $n = ($fonte === 'viewers') ? kick_viewers($usuario_id) : kick_subs($usuario_id);
            if ($n === null) throw new RuntimeException('O Kick não está conectado, ou recusou a leitura.');
            $corpo = ['total' => $n];
            $ok = true;
            $http = 200;
        } else {
        $bid = tw_broadcaster_id($usuario_id);
        if ($fonte === 'viewers') {
            /* Fora do ar a Helix devolve lista vazia, e isso NAO e falha: e
               zero de verdade. Tratar como falha deixaria o numero de ontem
               congelado na tela com a live desligada. */
            [$http, $corpo] = tw_helix($usuario_id, 'GET', '/streams', ['user_id' => $bid, 'first' => 1]);
            $ok = ($http === 200 && isset($corpo['data']));
            if ($ok) $corpo['total'] = (int) ($corpo['data'][0]['viewer_count'] ?? 0);
        } else {
            [$http, $corpo] = $fonte === 'seguidores'
                ? tw_helix($usuario_id, 'GET', '/channels/followers', ['broadcaster_id' => $bid, 'first' => 1])
                : tw_helix($usuario_id, 'GET', '/subscriptions',      ['broadcaster_id' => $bid, 'first' => 1]);
            $ok = ($http === 200 && isset($corpo['total']));
        }
        }
    } catch (Throwable $e) {
        /* GUARDAR A MENSAGEM, E NÃO SÓ "deu erro".

           As três coisas que estouram aqui já dizem exatamente o que houve —
           "este canal ainda não entrou com a Twitch", "o acesso expirou",
           "canal sem id da Twitch" — e eu jogava as três fora, virando todas
           num "não consegui falar com a Twitch agora" que não ajuda ninguém a
           consertar nada. */
        $ok = false;
        $http = 0;
        $excecao = $e->getMessage();
    }

    if (!$ok) {
        /* POR QUE FALHOU, EM PORTUGUES.

           Sem isto a meta simplesmente ficava parada no numero antigo e
           ninguem descobria o motivo — nem quem transmite, nem eu. O caso
           comum e o 401: a pessoa entrou no site antes de a permissao de
           seguidores existir, e o token dela nao tem o escopo. Isso nao se
           resolve sozinho: ela precisa entrar de novo. */
        $motivo = isset($excecao) && $excecao !== ''
            ? mb_substr($excecao, 0, 80)
            : match ((int) $http) {
                401     => 'permissao',   /* token velho ou sem o escopo */
                403     => 'proibido',    /* escopo existe mas a Twitch recusou */
                429     => 'espera',
                0       => 'sem-resposta',
                default => 'erro-' . (int) $http,
            };

        /* Adia a próxima tentativa sem mexer no valor: com a Twitch fora do ar,
           tentar a cada batida do OBS seria bater na porta dela sem parar. */
        db()->prepare(
            'INSERT INTO contagens (usuario_id, fonte, valor, erro) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE atualizado_em = NOW(), erro = VALUES(erro)'
        )->execute([$usuario_id, $chave, $anterior ?? 0, $motivo]);

        return $anterior;
    }

    $valor = (int) $corpo['total'];
    db()->prepare(
        'INSERT INTO contagens (usuario_id, fonte, valor, erro) VALUES (?, ?, ?, \'\')
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW(), erro = \'\''
    )->execute([$usuario_id, $chave, $valor]);

    /* Bateu a meta? Só a Twitch alimenta o subathon por enquanto: as colunas
       de alvo sao <fonte>_alvo, sem plataforma, e misturar duas plataformas
       no mesmo alvo daria um numero que nao quer dizer nada. */
    if ($plataforma === 'twitch') meta_bateu($usuario_id, $fonte, $valor);

    if ($plataforma === 'twitch' && $fonte === 'seguidores') {
        require_once __DIR__ . '/notificacoes.php';
        notifica_marco($usuario_id, (int) ($anterior ?? 0), $valor);
    }

    return $valor;
}

/**
 * Como esta a contagem, pra tela poder explicar em vez de ficar muda.
 */
function contagem_estado(int $usuario_id, string $fonte, string $plataforma = 'twitch'): array
{
    if (!contagem_vale($plataforma, $fonte)) return ['erro' => 'fonte'];

    $st = db()->prepare(
        'SELECT valor, erro, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade
           FROM contagens WHERE usuario_id = ? AND fonte = ?'
    );
    $st->execute([$usuario_id, contagem_chave($plataforma, $fonte)]);
    $l = $st->fetch();

    return [
        'fonte' => $fonte,
        'valor' => $l ? (int) $l['valor'] : null,
        'idade' => $l ? (int) $l['idade'] : null,
        'erro'  => $l ? (string) $l['erro'] : 'nunca',
    ];
}
