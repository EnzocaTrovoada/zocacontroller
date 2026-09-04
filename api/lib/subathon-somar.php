<?php
/**
 * Somar tempo no subathon.
 *
 * Mora aqui porque dois caminhos precisam dela: a ponte, quando vê sub ou
 * bits no chat, e o EventSub, quando alguém segue. Duas cópias da mesma
 * conta acabariam divergindo — e divergir aqui significa tempo errado no ar.
 */
require_once __DIR__ . '/db.php';

const SUB_CAMPOS = ['seg_sub1', 'seg_sub2', 'seg_sub3', 'seg_bits', 'seg_follow', 'seg_real', 'teto_evento'];

function subathon_config(int $usuario_id): ?array
{
    $st = db()->prepare('SELECT * FROM subathon WHERE usuario_id = ?');
    $st->execute([$usuario_id]);
    return $st->fetch() ?: null;
}

/**
 * Lê o overlay no servidor do relógio, soma e regrava.
 *
 * Pausado guarda quanto falta; correndo guarda quando acaba. São contas
 * diferentes, e somar no campo errado faria o tempo sumir.
 */
function rl_base(): string
{
    return rtrim(cfg()['relogio_base'] ?? 'https://relogio.zocahop.com', '/');
}

/** Lê o estilo publicado. Devolve null e a mensagem em $erro se não der. */
function rl_ler_estilo(string $slug, ?string &$erro = null): ?array
{
    $ch = curl_init(rl_base() . '/estilos/' . rawurlencode($slug) . '.json?t=' . time());
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $cru  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || !$cru) {
        $erro = 'Não achei o overlay do subathon. O link ainda existe?';
        return null;
    }
    $estilo = json_decode($cru, true);
    if (!is_array($estilo)) {
        $erro = 'O overlay respondeu algo que não entendi.';
        return null;
    }
    return $estilo;
}

/**
 * Republica o estilo.
 *
 * $mexeNoTempo diz se as chaves de tempo do pedido valem. Só a soma de evento
 * manda true; qualquer outra coisa manda false e o servidor do relógio guarda
 * o tempo que já estava lá — é o que impede uma gravação de aparência de
 * apagar os minutos que entraram de sub no meio do caminho.
 */
function rl_salvar_estilo(string $slug, string $token, array $estilo, bool $mexeNoTempo): array
{
    $ch = curl_init(rl_base() . '/salvar.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POSTFIELDS     => json_encode([
            'acao'  => 'salvar', 'slug' => $slug, 'token' => $token,
            'cfg'   => $estilo,  'tempo' => $mexeNoTempo ? 1 : 0,
        ]),
    ]);
    $resp = json_decode((string) curl_exec($ch), true);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || empty($resp['ok'])) {
        /* Transitório: o overlay pode estar só lento. Quem chama NÃO deve
           desistir por dez minutos por causa disto. */
        return ['ok' => false, 'transitorio' => true,
                'erro' => $resp['erro'] ?? 'O servidor do overlay recusou a gravação.'];
    }
    return ['ok' => true];
}

/**
 * Manda pro overlay quanto vale cada coisa, pra ele desenhar a linha
 * "SUB = 5 MIN · 100 BITS = 1 MIN" embaixo do cronômetro.
 *
 * Vai junto de cada gravação da configuração, então a legenda nunca fica
 * dizendo um número que já mudou: quem manda continua sendo esta tabela, e o
 * overlay só desenha. Zerado some sozinho lá.
 */
function subathon_publica_valores(array $c): array
{
    $erro = null;
    $estilo = rl_ler_estilo($c['slug'], $erro);
    if ($estilo === null) return ['ok' => false, 'erro' => $erro, 'transitorio' => true];

    $estilo['vsub1']   = (int) $c['seg_sub1'];
    $estilo['vsub2']   = (int) $c['seg_sub2'];
    $estilo['vsub3']   = (int) $c['seg_sub3'];
    $estilo['vbits']   = (int) $c['seg_bits'];
    $estilo['vfollow'] = (int) $c['seg_follow'];
    $estilo['vreal']   = (int) $c['seg_real'];

    return rl_salvar_estilo($c['slug'], $c['token'], $estilo, false);
}

function subathon_gravar(array $c, int $segundos): array
{
    $erro = null;
    $estilo = rl_ler_estilo($c['slug'], $erro);
    if ($estilo === null) return ['ok' => false, 'erro' => $erro, 'transitorio' => true];

    $agora = time();
    if (($estilo['modo'] ?? 'pausado') === 'rodando') {
        // Se o fim já passou, conta a partir de agora: senão a primeira sub
        // depois do zero somaria no passado e não apareceria na tela.
        $fim = max((int) ($estilo['fim'] ?? 0), $agora);
        $estilo['fim'] = $fim + $segundos;
        $restante = $estilo['fim'] - $agora;
    } else {
        $estilo['restante'] = max(0, (int) ($estilo['restante'] ?? 0) + $segundos);
        $restante = $estilo['restante'];
    }

    /* true: esta é a única gravação que PODE mexer no tempo. */
    $r = rl_salvar_estilo($c['slug'], $c['token'], $estilo, true);
    if (empty($r['ok'])) return $r;

    return ['ok' => true, 'restante' => $restante];
}

/**
 * O caminho inteiro: decide quantos segundos, marca o evento e grava.
 *
 * $d precisa de: tipo, chave (id único na origem) e, quando fizer sentido,
 * quantidade, quem e detalhe.
 */
function subathon_somar(int $usuario_id, array $d): array
{
    $c = subathon_config($usuario_id);
    /* 'parar' separa "nunca vai funcionar" de "não funcionou agora". A ponte
       só fica quieta de verdade no primeiro caso; no segundo ela volta a
       tentar em segundos. Sem essa separação, um soluço do servidor do
       overlay calava o subathon por dez minutos — e num raid isso é o raid
       inteiro. */
    if (!$c)           return ['ok' => false, 'erro' => 'Subathon não configurado.', 'parar' => true];
    if (!$c['ligado']) return ['ok' => true, 'ignorado' => 'subathon desligado'];

    $tipo  = (string) ($d['tipo'] ?? '');
    $chave = mb_substr(trim((string) ($d['chave'] ?? '')), 0, 120);
    if ($chave === '') return ['ok' => false, 'erro' => 'Falta o id do evento.'];

    $qtd = max(0, (float) ($d['quantidade'] ?? 1));
    $segundos = match ($tipo) {
        'sub1'   => (int) $c['seg_sub1'] * (int) $qtd,
        'sub2'   => (int) $c['seg_sub2'] * (int) $qtd,
        'sub3'   => (int) $c['seg_sub3'] * (int) $qtd,
        'bits'   => (int) round($c['seg_bits'] * $qtd / 100),
        'follow' => (int) $c['seg_follow'],
        'real'   => (int) round($c['seg_real'] * $qtd),
        default  => -1,
    };

    if ($segundos < 0)   return ['ok' => false, 'erro' => 'Tipo de evento desconhecido.'];
    if ($segundos === 0) return ['ok' => true, 'ignorado' => 'esse tipo está zerado'];

    // Teto por evento: um cheer gigante não pode virar dois dias de live.
    $segundos = min($segundos, (int) $c['teto_evento']);

    // Marcar ANTES de somar. O chat reentrega mensagem quando a conexão cai,
    // e a Twitch entrega "pelo menos uma vez" — o UNIQUE barra os dois casos.
    try {
        db()->prepare(
            'INSERT INTO subathon_eventos (usuario_id, chave, tipo, quem, detalhe, segundos)
                  VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $usuario_id, $chave, $tipo,
            mb_substr((string) ($d['quem'] ?? ''), 0, 64) ?: null,
            mb_substr((string) ($d['detalhe'] ?? ''), 0, 64) ?: null,
            $segundos,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return ['ok' => true, 'ignorado' => 'evento repetido'];
        throw $e;
    }

    /*
     * Trava por streamer enquanto le-soma-regrava.
     *
     * Sem isto, dois eventos no mesmo instante — e num raid com subs de
     * presente eles chegam aos montes — leem o MESMO tempo final, cada um
     * soma o seu, e o segundo sobrescreve o primeiro. O tempo de um dos dois
     * some, e ninguem descobre por que.
     *
     * Se a trava nao vier em 5 segundos, segue mesmo assim: perder um pouco
     * de tempo e melhor do que travar a live inteira.
     */
    $trava = 'zc_sub_' . $usuario_id;
    db()->prepare('SELECT GET_LOCK(?, 5)')->execute([$trava]);

    $r = subathon_gravar($c, $segundos);

    db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$trava]);

    if (empty($r['ok'])) {
        // Não gravou: desmarca. Senão o evento ficaria contado e o tempo
        // nunca entraria — e ninguém descobriria por quê.
        db()->prepare('DELETE FROM subathon_eventos WHERE usuario_id = ? AND chave = ?')
            ->execute([$usuario_id, $chave]);
        return ['ok' => false, 'erro' => $r['erro'], 'transitorio' => !empty($r['transitorio'])];
    }

    return ['ok' => true, 'somou' => $segundos, 'restante' => $r['restante']];
}
