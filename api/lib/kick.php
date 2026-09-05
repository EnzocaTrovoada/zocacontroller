<?php
/**
 * A conta do Kick.
 *
 * Mesma regra do Spotify: o token dá acesso à conta de quem transmite, então
 * ele NUNCA sai daqui. O navegador nunca vê, o overlay nunca vê.
 *
 * A diferença é como os eventos chegam. Na Twitch a ponte lê o chat sozinha,
 * por uma conexão anônima dentro do OBS. No Kick não existe leitura anônima:
 * o Kick ENTREGA os eventos num webhook nosso. Isso é melhor pra esta
 * hospedagem, não pior — webhook é justamente o que PHP compartilhado faz
 * bem, sem processo longo e sem ficar perguntando de tempos em tempos.
 */
require_once __DIR__ . '/db.php';

/* Preenchidos a partir da documentação oficial — ver api/kick.php. */
const KICK_AUTORIZA = 'https://id.kick.com/oauth/authorize';
const KICK_TOKEN    = 'https://id.kick.com/oauth/token';
const KICK_API      = 'https://api.kick.com/public/v1';

/* O que a gente pede, e por quê:
     events:subscribe  — é por ele que chat, follow e sub chegam no webhook
     user:read         — pra saber de quem é a conta e amarrar aqui
     channel:read      — nome do canal e quem está assistindo
     chat:write        — responder no chat, que na Twitch não dá (lá a leitura
                         é anônima e conexão anônima não fala) */
const KICK_ESCOPOS = 'user:read channel:read events:subscribe chat:write';

function kick_cfg(): array
{
    $c = cfg()['kick'] ?? [];
    if (empty($c['client_id']) || empty($c['client_secret'])) {
        throw new RuntimeException('O Kick não está configurado neste servidor.');
    }
    return $c;
}

function kick_redirect(): string
{
    return rtrim(cfg()['api_base'] ?? 'https://api.zocahop.com', '/') . '/kick.php';
}

function kick_webhook(): string
{
    return rtrim(cfg()['api_base'] ?? 'https://api.zocahop.com', '/') . '/kick-eventos.php';
}

function kick_http(string $metodo, string $url, array $cabecalhos = [], $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $cabecalhos,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);

    $resposta = curl_exec($ch);
    $codigo   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, json_decode((string) $resposta, true)];
}

/* ------------------------------------------------------------------ *
 *  PKCE
 *
 *  O Kick usa OAuth 2.1, e nele o PKCE é obrigatório mesmo para quem tem
 *  segredo. O verificador nasce aqui, fica guardado no banco entre a ida e a
 *  volta, e a volta o consome.
 *
 *  Ele NÃO vai no state: o state passa pelo navegador de quem clicou, e um
 *  verificador que passa pelo navegador não protege de nada — é exatamente
 *  o que o PKCE existe pra evitar.
 * ------------------------------------------------------------------ */

/** Sem = + / no caminho: o desafio viaja numa URL. */
function kick_b64url(string $bruto): string
{
    return rtrim(strtr(base64_encode($bruto), '+/', '-_'), '=');
}

function kick_novo_verificador(int $usuario_id): string
{
    $v = kick_b64url(random_bytes(48));
    db()->prepare(
        'INSERT INTO kick (usuario_id, verificador, verificador_em) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE verificador = VALUES(verificador), verificador_em = VALUES(verificador_em)'
    )->execute([$usuario_id, $v, time()]);
    return $v;
}

/**
 * Pega e QUEIMA o verificador. Uma volta de OAuth só vale uma vez; deixar o
 * verificador vivo depois de usado é deixar uma porta aberta sem motivo.
 */
function kick_consome_verificador(int $usuario_id): ?string
{
    $st = db()->prepare('SELECT verificador, verificador_em FROM kick WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    $l = $st->fetch();
    if (!$l || empty($l['verificador'])) return null;

    db()->prepare('UPDATE kick SET verificador = NULL, verificador_em = 0 WHERE usuario_id = ?')
        ->execute([$usuario_id]);

    /* Dez minutos. Um verificador velho é um verificador que não serve mais. */
    if (time() - (int) $l['verificador_em'] > 600) return null;
    return (string) $l['verificador'];
}

function kick_desafio(string $verificador): string
{
    return kick_b64url(hash('sha256', $verificador, true));
}

/* ------------------------------------------------------------------ *
 *  Token
 * ------------------------------------------------------------------ */

/**
 * Guarda o que voltou da troca.
 *
 * Dois SQLs diferentes de propósito, pelo mesmo motivo do Spotify: quando a
 * renovação não devolve refresh_token novo, gravar vazio por cima
 * desconectaria a pessoa horas depois, sem ela ter feito nada.
 */
function kick_guardar(int $usuario_id, array $t): void
{
    $expira = time() + max(60, (int) ($t['expires_in'] ?? 3600)) - 60;

    if (!empty($t['refresh_token'])) {
        db()->prepare(
            'INSERT INTO kick (usuario_id, token, refresh_token, expira_em) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE token = VALUES(token),
                                     refresh_token = VALUES(refresh_token),
                                     expira_em = VALUES(expira_em)'
        )->execute([$usuario_id, (string) $t['access_token'], (string) $t['refresh_token'], $expira]);
        return;
    }

    db()->prepare(
        'INSERT INTO kick (usuario_id, token, expira_em) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE token = VALUES(token), expira_em = VALUES(expira_em)'
    )->execute([$usuario_id, (string) $t['access_token'], $expira]);
}

/** O token de agora, renovado se estiver perto de vencer. Null = desconectado. */
function kick_token(int $usuario_id): ?string
{
    $st = db()->prepare('SELECT token, refresh_token, expira_em FROM kick WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    $l = $st->fetch();
    if (!$l || empty($l['token'])) return null;

    if (time() < (int) $l['expira_em']) return (string) $l['token'];
    if (empty($l['refresh_token'])) return null;

    $c = kick_cfg();
    [$http, $t] = kick_http('POST', KICK_TOKEN, ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => (string) $l['refresh_token'],
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
        ]));

    if ($http !== 200 || empty($t['access_token'])) return null;
    kick_guardar($usuario_id, $t);
    return (string) $t['access_token'];
}

/** Uma chamada autenticada na API do Kick. */
function kick_chamar(int $usuario_id, string $metodo, string $caminho, $corpo = null): array
{
    $token = kick_token($usuario_id);
    if (!$token) return [0, null];

    $cab = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($corpo !== null) $cab[] = 'Content-Type: application/json';

    return kick_http($metodo, KICK_API . $caminho, $cab,
        $corpo === null ? null : json_encode($corpo, JSON_UNESCAPED_UNICODE));
}

/** De quem é esta conta do lado do Kick. */
function kick_eu(int $usuario_id): ?array
{
    [$http, $d] = kick_chamar($usuario_id, 'GET', '/users');
    if ($http !== 200) return null;
    /* A API do Kick devolve os dados dentro de 'data'. */
    $u = $d['data'][0] ?? ($d['data'] ?? null);
    return is_array($u) ? $u : null;
}

/* ------------------------------------------------------------------ *
 *  A chave pública do Kick, pra conferir a assinatura do webhook
 *
 *  Vem de um endpoint público. Fica em arquivo porque webhook é caminho
 *  quente: buscar a chave na internet a cada evento seria pendurar a
 *  entrega de cada sub num pedido de rede que pode demorar ou falhar.
 *
 *  E é recarregada de tempos em tempos porque a doc do Kick NÃO diz com que
 *  frequência a chave muda nem se há período de sobreposição. Guardar pra
 *  sempre é apostar que nunca muda; buscar toda vez é apostar que a rede
 *  nunca falha. Um dia de validade fica no meio.
 * ------------------------------------------------------------------ */
function kick_chave_publica(): ?string
{
    $arquivo = sys_get_temp_dir() . '/kick_public_key.pem';

    if (is_readable($arquivo) && time() - (int) filemtime($arquivo) < 86400) {
        $pem = (string) file_get_contents($arquivo);
        if (str_contains($pem, 'BEGIN PUBLIC KEY')) return $pem;
    }

    [$http, $d] = kick_http('GET', KICK_API . '/public-key', ['Accept: application/json']);
    $pem = (string) ($d['data']['public_key'] ?? '');

    if ($http === 200 && str_contains($pem, 'BEGIN PUBLIC KEY')) {
        @file_put_contents($arquivo, $pem);
        return $pem;
    }

    /* Buscar falhou. Se existe uma cópia velha, ela ainda vale mais do que
       nada: chave velha pode até rejeitar evento novo, mas ficar sem chave
       rejeita TODOS — e aí o streamer perde os eventos sem saber por quê. */
    if (is_readable($arquivo)) {
        $velha = (string) file_get_contents($arquivo);
        if (str_contains($velha, 'BEGIN PUBLIC KEY')) return $velha;
    }

    /* ÚLTIMO RECURSO: a chave que está escrita na documentação do Kick.

       Ela existe aqui porque o api.kick.com fica atrás de Cloudflare, e
       Cloudflare gosta de barrar IP de datacenter — que é exatamente o que uma
       hospedagem compartilhada é. Se o servidor não conseguir buscar a chave,
       sem esta cópia TODO webhook seria recusado, e o streamer perderia os
       eventos do Kick sem nenhuma pista do motivo.

       Conferida contra o endpoint: o PEM publicado na doc e o que o endpoint
       devolve são iguais byte a byte. Se um dia o Kick trocar a chave e este
       servidor estiver bloqueado, os eventos param — e aí o conserto é trocar
       estas linhas. É um risco conhecido e escrito, não uma surpresa. */
    return "-----BEGIN PUBLIC KEY-----
"
        . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAq/+l1WnlRrGSolDMA+A8
"
        . "6rAhMbQGmQ2SapVcGM3zq8ANXjnhDWocMqfWcTd95btDydITa10kDvHzw9WQOqp2
"
        . "MZI7ZyrfzJuz5nhTPCiJwTwnEtWft7nV14BYRDHvlfqPUaZ+1KR4OCaO/wWIk/rQ
"
        . "L/TjY0M70gse8rlBkbo2a8rKhu69RQTRsoaf4DVhDPEeSeI5jVrRDGAMGL3cGuyY
"
        . "6CLKGdjVEM78g3JfYOvDU/RvfqD7L89TZ3iN94jrmWdGz34JNlEI5hqK8dd7C5EF
"
        . "BEbZ5jgB8s8ReQV8H+MkuffjdAj3ajDDX3DOJMIut1lBrUVD1AaSrGCKHooWoL2e
"
        . "twIDAQAB
"
        . "-----END PUBLIC KEY-----
";
}

/** De quem é o canal deste evento. O Kick manda o id do canal, não a nossa chave. */
function kick_dono_do_evento(array $d): ?int
{
    /* O id do dono aparece em lugares diferentes conforme o tipo de evento —
       por isso procuro nos que a doc mostra, em vez de fixar um só. */
    $id = $d['broadcaster']['user_id']
       ?? $d['broadcaster_user_id']
       ?? $d['channel']['broadcaster_user_id']
       ?? $d['channel_id']
       ?? null;
    if ($id === null || $id === '') return null;

    $st = db()->prepare('SELECT usuario_id FROM kick WHERE kick_id = ? LIMIT 1');
    $st->execute([(string) $id]);
    $u = $st->fetchColumn();
    return $u ? (int) $u : null;
}

/* ------------------------------------------------------------------ *
 *  O que a gente assina
 *
 *  Chat NÃO entra nesta lista, de propósito. A doc do Kick diz que app não
 *  verificado tem limite de 1.000 inscrições no chat.message.sent — e hoje o
 *  chatbox lê o chat da Twitch direto do navegador, então assinar chat aqui
 *  gastaria a cota mais escassa que existe sem ligar em lugar nenhum.
 *  Quando o chat unificado for feito, entra.
 * ------------------------------------------------------------------ */
const KICK_EVENTOS = [
    'channel.followed',
    'channel.subscription.new',
    'channel.subscription.renewal',
    'channel.subscription.gifts',
];

/**
 * Liga os avisos do Kick pra este streamer.
 *
 * Com token de usuário o Kick descobre o canal sozinho pelo próprio token —
 * por isso não mandamos broadcaster_user_id. E a URL do webhook não vai aqui:
 * ela mora nas configurações do app, no painel do Kick.
 */
function kick_assinar(int $usuario_id): array
{
    $eventos = array_map(fn($n) => ['name' => $n, 'version' => 1], KICK_EVENTOS);

    [$http, $r] = kick_chamar($usuario_id, 'POST', '/events/subscriptions', [
        'method' => 'webhook',
        'events' => $eventos,
    ]);

    if ($http !== 200 || !isset($r['data'])) {
        return ['ok' => false, 'erro' => 'O Kick recusou ligar os avisos (' . $http . ').'];
    }

    /* 200 NÃO quer dizer que todas passaram: cada item traz o próprio erro.
       Tratar o 200 como sucesso geral esconderia metade das falhas. */
    $feitas = [];
    $erros  = [];
    foreach ((array) $r['data'] as $item) {
        $nome = (string) ($item['name'] ?? '?');
        if (!empty($item['error'])) { $erros[] = $nome . ': ' . $item['error']; continue; }
        $feitas[] = $nome;
        db()->prepare(
            'INSERT INTO kick_assinaturas (usuario_id, tipo, kick_sub_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE kick_sub_id = VALUES(kick_sub_id)'
        )->execute([$usuario_id, $nome, (string) ($item['subscription_id'] ?? '')]);
    }
    return ['ok' => !empty($feitas), 'feitas' => $feitas, 'erros' => $erros];
}

/* ------------------------------------------------------------------ *
 *  Do evento do Kick pro mesmo lugar onde o da Twitch cai
 *
 *  O resto do sistema — feed, alerta, subathon, gatilho — não precisa saber
 *  de qual plataforma veio. Por isso a tradução acontece aqui e só aqui: o
 *  evento do Kick entra pela mesma porta que o da Twitch, o subathon_somar.
 * ------------------------------------------------------------------ */
function kick_tratar(int $usuario_id, string $tipo, array $d, string $msgId): void
{
    require_once __DIR__ . '/subathon-somar.php';

    $nome = function (?array $u): string {
        return trim((string) ($u['username'] ?? '')) ?: 'alguém';
    };

    if ($tipo === 'channel.followed') {
        subathon_somar($usuario_id, [
            'tipo'    => 'follow',
            'chave'   => 'kick:' . $msgId,
            'quem'    => $nome($d['follower'] ?? null),
            'detalhe' => 'começou a seguir no Kick',
        ]);
        return;
    }

    if ($tipo === 'channel.subscription.new' || $tipo === 'channel.subscription.renewal') {
        /* O Kick não manda o tier em nenhum dos eventos de assinatura — não dá
           pra inventar sub2/sub3, então vale o de baixo. */
        subathon_somar($usuario_id, [
            'tipo'    => 'sub1',
            'chave'   => 'kick:' . $msgId,
            'quem'    => $nome($d['subscriber'] ?? null),
            'detalhe' => $tipo === 'channel.subscription.new' ? 'assinou no Kick' : 'renovou no Kick',
        ]);
        return;
    }

    if ($tipo === 'channel.subscription.gifts') {
        /* NÃO EXISTE CAMPO DE QUANTIDADE: o tamanho da lista de presenteados é
           a quantidade. Um pacote de vinte chega como um evento com vinte
           pessoas dentro — e de brinde a gente sabe quem recebeu cada um,
           coisa que a Twitch não dá. */
        $quantos = max(1, count((array) ($d['giftees'] ?? [])));

        /* No presente anônimo TODOS os campos do doador viram null, inclusive
           o id. Ler ['gifter']['username'] direto quebraria. */
        $g = $d['gifter'] ?? null;
        $quem = (is_array($g) && empty($g['is_anonymous'])) ? $nome($g) : 'Anônimo';

        subathon_somar($usuario_id, [
            'tipo'       => 'sub1',
            'chave'      => 'kick:' . $msgId,
            'quem'       => $quem,
            'quantidade' => $quantos,
            'detalhe'    => 'presente no Kick',
        ]);
        return;
    }
}
