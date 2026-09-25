<?php
/**
 * Está tudo funcionando?
 *
 * QUASE TODO PROBLEMA DESTE PRODUTO É INVISÍVEL DE DENTRO DELE.
 *
 * Uma coluna curta demais, um SQL que não foi rodado, a fonte do OBS com
 * código velho em cache, uma cena que não chegou a ser gravada, uma
 * permissão da Twitch que a conta nunca deu. Nenhum desses dá erro: o
 * recurso simplesmente não acontece, e a pessoa conclui que o site
 * quebrou.
 *
 * Esta tela responde de uma vez — e a resposta chega antes do e-mail.
 *
 * Cada conferência devolve um estado:
 *   ok      — funcionando
 *   aviso   — funciona, mas tem algo pra arrumar
 *   parado  — não vai funcionar assim
 *   nao_usa — a pessoa não usa isto, e não é problema
 *
 * A versão da ponte NÃO é conferida aqui: quem sabe qual é a esperada é o
 * site, que carrega o PONTE_VERSAO junto. O servidor só repassa a que a
 * fonte disse estar rodando, e a comparação acontece lá.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/twitch.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

$itens = [];

function saude_poe(array &$itens, string $oQue, string $estado, string $recado, string $onde = ''): void
{
    $itens[] = ['o_que' => $oQue, 'estado' => $estado, 'recado' => $recado, 'onde' => $onde];
}

/* ---------- a conta na Twitch ---------- */
$escopos = tw_escopos($uid);
$bid = tw_broadcaster_id($uid);

if (!$bid) {
    saude_poe($itens, 'Conta da Twitch', 'parado',
        'A permissão venceu ou nunca foi dada. Nada que fale com a Twitch funciona assim.', '#/meu');
} else {
    /* Os escopos de que recursos inteiros dependem. Faltando um, o recurso
       dele fica quieto — e o conserto é sempre o mesmo. */
    $faltando = array_values(array_diff(
        ['channel:read:redemptions', 'channel:manage:raids', 'moderator:read:followers'],
        $escopos
    ));

    if ($faltando) {
        saude_poe($itens, 'Permissões da Twitch', 'aviso',
            'Faltam ' . count($faltando) . '. Entrar de novo resolve todas de uma vez.', '#/meu');
    } else {
        saude_poe($itens, 'Conta da Twitch', 'ok', 'Conectada, com todas as permissões.');
    }
}

/* ---------- os avisos que chegam por fora do chat ---------- */
$assinaturas = [];
try {
    $st = db()->prepare('SELECT tipo, estado FROM eventsub_assinaturas WHERE usuario_id = ?');
    $st->execute([$uid]);
    $assinaturas = $st->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { /* sem tabela */ }

/* Compara pelos 48 primeiros: a coluna era curta e cortava os nomes
   longos, então quem assinou antes do SQL 067 tem a linha pela metade. */
$temEvento = static function (string $tipo) use ($assinaturas): bool {
    foreach ($assinaturas as $t => $e) {
        if (substr($tipo, 0, 48) === substr((string) $t, 0, 48)) {
            return $e !== 'revoked' && $e !== 'authorization_revoked';
        }
    }
    return false;
};

if (!$assinaturas) {
    saude_poe($itens, 'Avisos da Twitch', 'parado',
        'Nenhum ligado. Sem eles, follow, raid e resgate de pontos não chegam aqui.', '#/meu');
} elseif (!$temEvento('channel.channel_points_custom_reward_redemption.add')
       || !$temEvento('channel.raid')) {
    saude_poe($itens, 'Avisos da Twitch', 'aviso',
        'Os avisos novos não foram ligados. Ligar de novo cria só o que falta.', '#/meu');
} else {
    saude_poe($itens, 'Avisos da Twitch', 'ok', count($assinaturas) . ' ligados.');
}

/* ---------- a fonte do OBS ---------- */
$versaoPonte = '';
try {
    $st = db()->prepare(
        'SELECT estado, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade
           FROM estado_ao_vivo WHERE usuario_id = ?'
    );
    $st->execute([$uid]);
    $e = $st->fetch();

    if (!$e) {
        saude_poe($itens, 'Fonte do OBS (a ponte)', 'nao_usa',
            'Nunca publicou nada. Sem ela o chat não controla o OBS, mas os overlays funcionam.',
            '#/tutorial');
    } elseif ((int) $e['idade'] > 120) {
        saude_poe($itens, 'Fonte do OBS (a ponte)', 'nao_usa',
            'Calada há ' . max(1, intdiv((int) $e['idade'], 60)) . ' min — normal com o OBS fechado.',
            '#/tutorial');
    } else {
        $est = json_decode((string) $e['estado'], true) ?: [];
        $versaoPonte = (string) ($est['versao'] ?? '');

        if (empty($est['chat'])) {
            saude_poe($itens, 'Fonte do OBS (a ponte)', 'aviso',
                'Ligada, mas não está lendo o chat.', '#/tutorial');
        } else {
            saude_poe($itens, 'Fonte do OBS (a ponte)', 'ok', 'Ligada e lendo o chat.');
        }

        if (!empty($est['erro'])) {
            saude_poe($itens, 'Último erro da ponte', 'aviso', (string) $est['erro'], '#/tutorial');
        }
    }
} catch (Throwable $e) { /* sem tabela: nada a dizer */ }

/* ---------- os overlays ---------- */
$porTipo = [];
try {
    $st = db()->prepare('SELECT tipo, COUNT(*) FROM perfis WHERE usuario_id = ? GROUP BY tipo');
    $st->execute([$uid]);
    $porTipo = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $quantos = array_sum($porTipo);

    $acesso = acesso_do_usuario($uid);
    $teto = (int) recursos_do_usuario($uid, $acesso['ativo'] ? $acesso['plano'] : 'gratis')['perfis_max'];

    if (!$quantos) {
        saude_poe($itens, 'Overlays', 'nao_usa', 'Você ainda não criou nenhum.', '#/overlays');
    } elseif ($quantos > $teto) {
        /* Passando do teto, os mais novos param de desenhar — e param em
           silêncio, que é o pior jeito de um limite existir. */
        saude_poe($itens, 'Overlays', 'aviso',
            $quantos . ' criados e o seu plano desenha ' . $teto
            . '. Os mais novos não aparecem na live.', '#/planos');
    } else {
        saude_poe($itens, 'Overlays', 'ok', $quantos . ' de até ' . $teto . '.');
    }
} catch (Throwable $e) { /* sem tabela */ }

/* O TTS precisa da fonte dele: sem ela tudo o mais pode estar certo e não
   sai som nenhum — e esse é o defeito mais caro de procurar. */
try {
    $c = db()->prepare('SELECT ligado, premio_id FROM tts_config WHERE usuario_id = ?');
    $c->execute([$uid]);
    $t = $c->fetch();

    if (!$t || !(int) $t['ligado']) {
        saude_poe($itens, 'TTS', 'nao_usa', 'Desligado.', '#/bot');
    } elseif ((string) $t['premio_id'] === '') {
        saude_poe($itens, 'TTS', 'parado', 'Ligado, mas sem prêmio de pontos escolhido.', '#/bot');
    } elseif (empty($porTipo['tts'])) {
        saude_poe($itens, 'TTS', 'parado',
            'Ligado, mas a overlay que fala não existe. Nada sai no som.', '#/novo/tts');
    } else {
        saude_poe($itens, 'TTS', 'ok', 'Ligado, com prêmio e overlay.');
    }
} catch (Throwable $e) { /* sem tabela: o banco abaixo acusa */ }

/* ---------- o que falta no banco ---------- */
$semBanco = [];
foreach ([
    ['tts_config', null, 'TTS'],
    ['tts_config', 'calar_em', 'Pular a fala'],
    ['raid_lista', null, 'Raids'],
    ['raid_saldo', null, 'Pontos de raid'],
    ['contagem_regressiva', null, 'Contagem regressiva'],
    ['uso', null, 'Estatísticas de uso'],
    ['usuarios', 'cor_acento', 'Cor do site'],
    ['usuarios', 'painel_secoes', 'Seções do painel'],
    ['usuarios', 'alertas_ligados', 'Desligar alertas'],
    ['luzes_cenas', 'nome', 'Nome das cenas de luz'],
    ['assinaturas', 'dias_raid', 'Desconto por raid'],
] as [$tabela, $coluna, $oQue]) {
    try {
        db()->query('SELECT ' . ($coluna ? '`' . $coluna . '`' : '1') . ' FROM `' . $tabela . '` LIMIT 0');
    } catch (Throwable $e) {
        $semBanco[] = $oQue;
    }
}

if ($semBanco) {
    saude_poe($itens, 'Banco de dados', 'parado',
        count($semBanco) . ' sem onde guardar: ' . implode(', ', $semBanco) . '.', '#/meu');
} else {
    saude_poe($itens, 'Banco de dados', 'ok', 'Todas as tabelas no lugar.');
}

/* ---------- o resumo ---------- */
$parados = count(array_filter($itens, static fn($i) => $i['estado'] === 'parado'));
$avisos  = count(array_filter($itens, static fn($i) => $i['estado'] === 'aviso'));

json_saida([
    'tudo_bem'     => !$parados && !$avisos,
    'parados'      => $parados,
    'avisos'       => $avisos,
    'versao_ponte' => $versaoPonte,
    'itens'        => $itens,
]);
