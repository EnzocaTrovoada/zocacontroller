<?php
/**
 * Os raids: quem está ao vivo agora, e mandar o seu pessoal pra lá.
 *
 * GET                  → a sua lista ao vivo + os do ZocaHub ao vivo
 * POST {somar, login}  → põe alguém na lista
 * POST {tirar, login}  → tira
 * POST {raidar, login} → abre a janela de raid da Twitch
 *
 * A JANELA DE 90 SEGUNDOS É DA TWITCH, NÃO NOSSA. O POST /helix/raids abre
 * a contagem no chat; ela termina sozinha e o raid sai. Ninguém precisa
 * confirmar nada — mas também não é instantâneo, e a tela diz isso.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/seguranca.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/twitch.php';
require_once __DIR__ . '/lib/raid-pontos.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/** Os logins que este usuário acompanha. */
function raid_lista(int $uid): array
{
    try {
        $st = db()->prepare('SELECT login FROM raid_lista WHERE usuario_id = ? ORDER BY login');
        $st->execute([$uid]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Os logins de quem usa o ZocaHub, tirando quem pediu pra não aparecer.
 *
 * ISTO É O QUE FAZ O RECURSO VALER. Uma lista de três que você digitou é
 * pouco; "quem do ZocaHub está ao vivo agora" é descoberta, e faz a
 * comunidade se raidar sozinha. Por isso aparece pro grátis também.
 */
function raid_da_casa(int $menos): array
{
    /* TODO MUNDO, E A TWITCH DIZ QUEM ESTA AO VIVO.

       A primeira versao disto filtrava por ao_vivo_desde, que so e
       preenchido pelo evento stream.online. So que esse evento e novo:
       quem nao religou o EventSub nunca teve ele, e quem religou so passa
       a ter depois de abrir uma live. Resultado: a lista ficava vazia pra
       todo mundo, justo o recurso que existe pra mostrar gente.

       Perguntar a Twitch responde na hora e sem depender de nada nosso.
       Uma chamada cobre 100 logins, entao isto custa pouco. */
    try {
        $st = db()->prepare(
            'SELECT login FROM usuarios
              WHERE id <> ? AND LENGTH(login) > 0 AND raid_oculto = 0
              ORDER BY id DESC LIMIT 300'
        );
        $st->execute([$menos]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/* ---------- um streamer pequeno em português, ao vivo ----------

   Serve pra quem não tem lista e não conhece ninguém: dá uma pessoa de
   verdade pra raidar em vez de uma tela vazia. */
if (isset($_GET['aleatorio'])) {
    /* A primeira caminhada do quarto de hora demora: são várias páginas
       da Twitch. Daí a trava ser por pessoa e não por clique. */
    trava('raid-sorte', 20, 300);

    $pool = tw_pequenos_pt();
    if (!$pool) {
        json_saida(['erro' => 'Não achei ninguém pequeno ao vivo agora. Tente daqui a pouco.'], 404);
    }
    json_saida(['quem' => $pool[random_int(0, count($pool) - 1)], 'de' => count($pool)]);
}

/* ---------- o histórico ----------

   CADA RAID NUMA LINHA, COM O MOTIVO DE NÃO TER CONTADO.

   Os pontos recusam por seis motivos diferentes, e sem mostrar qual foi a
   pessoa só sabe que "não somou". Isso vira uma conversa de suporte por
   raid — e a resposta já estava no banco o tempo todo. */
if (isset($_GET['historico'])) {
    try {
        $st = db()->prepare(
            'SELECT alvo_login, espectadores, pontos, motivo, criado_em
               FROM raid_feitos WHERE usuario_id = ?
              ORDER BY id DESC LIMIT 40'
        );
        $st->execute([$uid]);
        json_saida(['historico' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        json_saida(['historico' => []]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $minha = raid_lista($uid);
    $casa  = raid_da_casa($uid);

    /* Uma chamada só pros dois grupos: são no máximo 200 logins, e o
       /streams aceita 100 por vez. Juntar antes evita pedir duas vezes o
       mesmo login quando alguém da sua lista também usa o ZocaHub. */
    $vivos = tw_ao_vivo(array_merge($minha, $casa));

    $daCasa = array_flip(array_map('mb_strtolower', $casa));
    $saida = [];
    foreach ($vivos as $login => $v) {
        $v['meu']  = in_array($login, array_map('mb_strtolower', $minha), true);
        $v['casa'] = isset($daCasa[$login]);
        $saida[] = $v;
    }

    /* Os do ZocaHub primeiro: raid pra quem é da casa vale o dobro de
       ponto, então mostrar eles embaixo seria esconder o melhor. */
    usort($saida, static fn($a, $b) => ($b['casa'] <=> $a['casa'])
        ?: ($b['espectadores'] <=> $a['espectadores']));

    $s = raid_saldo($uid);

    json_saida([
        'ao_vivo' => $saida,
        'lista'   => $minha,
        'limite'  => limite($uid, 'raid_lista', 3),
        'escopo'  => in_array('channel:manage:raids', tw_escopos($uid), true),
        'saldo'   => [
            'pontos'   => (int) $s['pontos'],
            'dias'     => (int) $s['dias'],
            'falta'    => RAID_PONTOS_DIA - ((int) $s['pontos'] % RAID_PONTOS_DIA),
            'mes'      => (int) $s['dias_mes'],
            'teto'     => RAID_DIAS_MES,
            'desconto' => (int) $s['desconto'],
        ],
    ]);
}

$d = corpo_json();

/* ---------- mexer na lista ---------- */
if (!empty($d['somar'])) {
    $login = mb_strtolower(trim((string) $d['somar']));

    /* O que a Twitch aceita como login, e nada além: isto vira parte de
       um endereço da API. */
    if (!preg_match('/^[a-z0-9_]{3,25}$/', $login)) {
        json_saida(['erro' => 'Esse nome não parece um canal da Twitch.'], 400);
    }

    $quantos = count(raid_lista($uid));
    $teto = limite($uid, 'raid_lista', 3);
    if ($teto !== null && $quantos >= (int) $teto) {
        json_saida(['erro' => 'No grátis dá pra acompanhar ' . (int) $teto . '. O Pro tira o limite.'], 402);
    }

    try {
        db()->prepare('INSERT IGNORE INTO raid_lista (usuario_id, login) VALUES (?, ?)')
            ->execute([$uid, $login]);
    } catch (Throwable $e) {
        json_saida(['erro' => 'Falta rodar o SQL 063 no banco.'], 500);
    }
    json_saida(['ok' => true]);
}

if (!empty($d['tirar'])) {
    db()->prepare('DELETE FROM raid_lista WHERE usuario_id = ? AND login = ?')
        ->execute([$uid, mb_strtolower(trim((string) $d['tirar']))]);
    json_saida(['ok' => true]);
}

/* ---------- a chave do modo desconto ----------

   Acumular é ilimitado; gastar é que tem teto. Quem prefere guardar os
   dias pra quando parar de pagar deixa isto desligado. */
if (isset($d['desconto'])) {
    try {
        db()->prepare('INSERT INTO raid_saldo (usuario_id, desconto) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE desconto = VALUES(desconto)')
            ->execute([$uid, !empty($d['desconto']) ? 1 : 0]);
    } catch (Throwable $e) {
        json_saida(['erro' => 'Falta rodar o SQL 063 no banco.'], 500);
    }
    json_saida(['ok' => true]);
}

/* ---------- não aparecer na lista da casa ---------- */
if (isset($d['oculto'])) {
    db()->prepare('UPDATE usuarios SET raid_oculto = ? WHERE id = ?')
        ->execute([!empty($d['oculto']) ? 1 : 0, $uid]);
    json_saida(['ok' => true]);
}

/* ---------- abrir a janela de raid ---------- */
if (!empty($d['raidar'])) {
    /* A Twitch aceita 10 chamadas por 10 minutos. Segurar aqui com folga é
       melhor que levar o erro dela: o nosso diz o que fazer. */
    trava('raid', 8, 600);

    $eu = tw_broadcaster_id($uid);
    if (!$eu) json_saida(['erro' => 'Entre de novo com a Twitch.'], 400);

    if (!in_array('channel:manage:raids', tw_escopos($uid), true)) {
        json_saida(['erro' => 'Entre de novo com a Twitch pra liberar o raid.'], 403);
    }

    $login = mb_strtolower(trim((string) $d['raidar']));
    $vivos = tw_ao_vivo([$login]);
    if (!isset($vivos[$login])) {
        json_saida(['erro' => 'Esse canal não está ao vivo agora.'], 400);
    }

    [$http, $r] = tw_helix($uid, 'POST', '/raids', [
        'from_broadcaster_id' => $eu,
        'to_broadcaster_id'   => $vivos[$login]['id'],
    ]);

    /* Cada recusa tem um motivo diferente, e "deu erro" não ajuda ninguém
       a resolver. */
    if ($http === 409) json_saida(['erro' => 'Você já tem um raid a caminho.'], 409);
    if ($http === 429) json_saida(['erro' => 'Muitos raids seguidos. Espere alguns minutos.'], 429);
    if ($http === 401) json_saida(['erro' => 'Entre de novo com a Twitch pra liberar o raid.'], 403);
    if ($http === 400) {
        json_saida(['erro' => (string) ($r['message'] ?? 'A Twitch recusou esse raid.')], 400);
    }
    if ($http !== 200) json_saida(['erro' => 'A Twitch não respondeu agora.'], 502);

    json_saida([
        'ok' => true,
        /* A tela precisa dizer isto, senão parece que não aconteceu nada. */
        'aviso' => 'Contagem aberta no seu chat. O raid sai em 90 segundos, ou agora se você clicar em Raid Now.',
    ]);
}

json_saida(['erro' => 'Pedido não entendido.'], 400);
