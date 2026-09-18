<?php
/**
 * Respostas no chat: o texto que um comando manda, com as variáveis
 * preenchidas, e o envio pela Twitch.
 *
 * As variáveis seguem o jeito do StreamElements — $(sender), ${1:},
 * $(random.pick 'a' 'b'), $(customapi URL)... — pra que um comando importado
 * de lá funcione aqui sem ninguém reescrever. As que dependem de coisa que
 * não existe aqui (pontos de fidelidade, IA, clima) viram texto vazio, e o
 * importador avisa antes de importar.
 *
 * DE FORA PRA DENTRO, E SÓ O QUE VAI SER USADO. O $(if) só avalia o lado
 * escolhido, então um $(count) no lado que não saiu não conta. E o que uma
 * variável devolve nunca é lido de novo: se o chat digitasse
 * "$(customapi ...)" como argumento, uma segunda leitura buscaria o endereço
 * que o chat escolheu.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/twitch.php';

const CHAT_MAX          = 500;    // o limite da Twitch por mensagem
const CHAT_API_BYTES    = 400;    // o mesmo corte do StreamElements
const CHAT_VARIAVEIS    = 60;     // variáveis por resposta
const CHAT_BUSCAS       = 3;      // endereços buscados por resposta
const CHAT_FUNDO        = 8;      // variável dentro de variável

/** O nível de acesso do StreamElements, pelo nosso cargo. */
const CHAT_NIVEL = ['chat' => 100, 'sub' => 250, 'vip' => 400, 'mod' => 500, 'supermod' => 1000, 'dono' => 1500];

/** Um $(if) falso sem "senão", ou um $(repeat) sem número: a resposta inteira não sai. */
final class ChatSilencio extends Exception {}

/**
 * O texto final da resposta, ou null quando ela não deve sair.
 *
 * $ctx: uid, comando (nome do comando, ou '' em gatilho), contador (nome do
 * contador próprio), quem (nome de exibição), login, id_twitch, cargo,
 * mensagem (a mensagem inteira, com o gatilho), msg_id, quanto (dos
 * gatilhos) e chatters (logins de quem falou há pouco).
 */
function chat_expande(string $modelo, array $ctx): ?string
{
    $estado = ['variaveis' => 0, 'buscas' => 0];
    try {
        $texto = chat_texto($modelo, $ctx, $estado, 0);
    } catch (ChatSilencio $e) {
        return null;
    }
    return chat_limpa($texto);
}

/** Espaços juntos, sem comando de chat no começo, no tamanho da Twitch. */
function chat_limpa(string $texto): string
{
    $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
    /* "/me" não existe pela API, e "/" ou "." no começo é comando de chat:
       nenhum dos dois pode sair de uma resposta. */
    $texto = (string) preg_replace('#^/me\s+#i', '', $texto);
    $texto = (string) preg_replace('#^(?:/+|\.(?!\.))(?=\pL)#u', '', $texto);
    return mb_substr(trim($texto), 0, CHAT_MAX);
}

/* ------------------------------------------------------------------ *
 *  A leitura
 * ------------------------------------------------------------------ */

/** Onde começa a próxima variável a partir de $i, ou -1. */
function chat_proxima(string $s, int $i): int
{
    $n = strlen($s);
    while (($p = strpos($s, '$', $i)) !== false) {
        if ($p + 1 < $n && ($s[$p + 1] === '(' || $s[$p + 1] === '{')) return $p;
        $i = $p + 1;
    }
    return -1;
}

/** Onde fecha a variável que abre em $p, respeitando aspas e variáveis de dentro. */
function chat_fecha(string $s, int $p): ?int
{
    $abre  = $s[$p + 1];
    $fecha = $abre === '(' ? ')' : '}';
    $n = strlen($s);
    $fundo = 0;
    $aspa = '';
    for ($i = $p + 2; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '$' && $i + 1 < $n && ($s[$i + 1] === '(' || $s[$i + 1] === '{')) {
            $f = chat_fecha($s, $i);
            if ($f === null) return null;
            $i = $f;
            continue;
        }
        if ($aspa !== '') {
            if ($c === $aspa) $aspa = '';
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $aspa = $c; continue; }
        if ($c === $abre) {
            $fundo++;
        } elseif ($c === $fecha) {
            if ($fundo === 0) return $i;
            $fundo--;
        }
    }
    return null;
}

/** Texto com variáveis: o texto comum passa, cada variável vira o valor dela. */
function chat_texto(string $s, array $ctx, array &$estado, int $fundo): string
{
    if ($fundo > CHAT_FUNDO) return '';
    $saida = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $p = chat_proxima($s, $i);
        if ($p < 0) {
            $saida .= substr($s, $i);
            break;
        }
        $fim = chat_fecha($s, $p);
        if ($fim === null) {
            /* Sem fechamento é texto comum, como no StreamElements. */
            $saida .= substr($s, $i, $p - $i + 2);
            $i = $p + 2;
            continue;
        }
        $saida .= substr($s, $i, $p - $i);
        if (++$estado['variaveis'] <= CHAT_VARIAVEIS) {
            $saida .= chat_variavel(substr($s, $p + 2, $fim - $p - 2), $ctx, $estado, $fundo + 1);
        }
        $i = $fim + 1;
        if (strlen($saida) > 4 * CHAT_MAX) break;
    }
    return $saida;
}

/** Os pedaços de dentro de uma variável, separados por espaço. Aspas juntam. */
function chat_pedacos(string $s): array
{
    $saida = [];
    $atual = '';
    $tem = false;
    $aspa = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '$' && $i + 1 < $n && ($s[$i + 1] === '(' || $s[$i + 1] === '{')) {
            $f = chat_fecha($s, $i);
            if ($f !== null) {
                $atual .= substr($s, $i, $f - $i + 1);
                $tem = true;
                $i = $f;
                continue;
            }
        }
        if ($aspa !== '') {
            if ($c === $aspa) $aspa = ''; else $atual .= $c;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $aspa = $c; $tem = true; continue; }
        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
            if ($tem) { $saida[] = $atual; $atual = ''; $tem = false; }
            continue;
        }
        $atual .= $c;
        $tem = true;
    }
    if ($tem) $saida[] = $atual;
    return $saida;
}

/** Separa "valor|padrão" no primeiro | fora de aspas e de variável de dentro. */
function chat_pipe(string $s): array
{
    $n = strlen($s);
    $aspa = '';
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '$' && $i + 1 < $n && ($s[$i + 1] === '(' || $s[$i + 1] === '{')) {
            $f = chat_fecha($s, $i);
            if ($f !== null) { $i = $f; continue; }
        }
        if ($aspa !== '') { if ($c === $aspa) $aspa = ''; continue; }
        if ($c === "'" || $c === '"' || $c === '`') { $aspa = $c; continue; }
        if ($c === '|') return [substr($s, 0, $i), substr($s, $i + 1)];
    }
    return [$s, null];
}

/* ------------------------------------------------------------------ *
 *  As variáveis
 * ------------------------------------------------------------------ */

function chat_variavel(string $dentro, array $ctx, array &$estado, int $fundo): string
{
    $dentro = trim($dentro);
    if ($dentro === '') return '';
    [$corpo, $padrao] = chat_pipe($dentro);
    $corpo = trim($corpo);
    $usaPadrao = function (string $v) use ($padrao, $ctx, &$estado, $fundo): string {
        return ($v === '' && $padrao !== null) ? chat_texto($padrao, $ctx, $estado, $fundo) : $v;
    };

    /* As palavras da mensagem: $(1), $(1:), $(2:4), $(:3), $(1 username). */
    if (preg_match('/^(\d*)(:?)(\d*)(?:[ .](\w+))?$/', $corpo, $m) && ($m[1] !== '' || $m[3] !== '')) {
        return $usaPadrao(chat_palavras($m, $ctx));
    }

    $pedacos = chat_pedacos($corpo);
    if (!$pedacos) return '';
    $primeiro = array_shift($pedacos);
    $ponto = strpos($primeiro, '.');
    $base = strtolower($ponto === false ? $primeiro : substr($primeiro, 0, $ponto));
    $sub  = $ponto === false ? '' : substr($primeiro, $ponto + 1);

    /* Cada pedaço só vira texto quando alguém pede por ele. */
    $valor = function (int $i) use ($pedacos, $ctx, &$estado, $fundo): ?string {
        return array_key_exists($i, $pedacos) ? chat_texto($pedacos[$i], $ctx, $estado, $fundo) : null;
    };
    $todos = function () use ($pedacos, $ctx, &$estado, $fundo): array {
        return array_map(fn($p) => chat_texto($p, $ctx, $estado, $fundo), $pedacos);
    };

    switch ($base) {
        case 'if':
            $cond = strtolower(trim((string) $valor(0)));
            if (in_array($cond, ['1', 't', 'true'], true)) {
                return $usaPadrao((string) $valor(1));
            }
            if (array_key_exists(2, $pedacos)) return (string) $valor(2);
            if ($padrao !== null) return chat_texto($padrao, $ctx, $estado, $fundo);
            throw new ChatSilencio();

        case 'sender':
        case 'source':
            return $usaPadrao(chat_pessoa($sub, (string) $ctx['quem'], (string) $ctx['login'], $ctx, true));

        case 'user': {
            $alvo = trim((string) $valor(0));
            if ($alvo === '') {
                $primeira = chat_palavra($ctx, 1);
                if (preg_match('/^@?\w{1,30}$/u', $primeira)) $alvo = $primeira;
            }
            if ($alvo === '') {
                return $usaPadrao(chat_pessoa($sub, (string) $ctx['quem'], (string) $ctx['login'], $ctx, true));
            }
            $alvo = ltrim($alvo, '@');
            $proprio = strtolower($alvo) === strtolower((string) $ctx['login']);
            return $usaPadrao(chat_pessoa($sub, $alvo, strtolower($alvo), $ctx, $proprio));
        }

        case 'touser': {
            $primeira = ltrim(chat_palavra($ctx, 1), '/.');
            return $usaPadrao($primeira !== '' ? $primeira : (string) $ctx['quem']);
        }

        case 'channel':
            return $usaPadrao(chat_canal_campo((int) $ctx['uid'], strtolower($sub), (string) $valor(0)));

        case 'game':
        case 'title':
        case 'uptime':
            return $usaPadrao(chat_canal_campo((int) $ctx['uid'], $base, (string) $valor(0)));

        case 'provider':
            return 'twitch';

        case 'msgid':
            return (string) ($ctx['msg_id'] ?? '');

        case 'quanto':
            /* O {quanto} dos gatilhos: quantos subs, bits ou reais. */
            return (string) ($ctx['quanto'] ?? '');

        case 'random':
            return $usaPadrao(chat_aleatorio(strtolower($sub), $pedacos, $valor, $ctx));

        case 'count': {
            $nome = $sub !== '' ? $sub : (string) $valor(0);
            $muda = $sub !== '' ? (string) $valor(0) : (string) $valor(1);
            if ($nome === '') $nome = (string) ($ctx['contador'] ?? '');
            return chat_contador((int) $ctx['uid'], $nome, $muda);
        }

        case 'getcount':
            return chat_contador_le((int) $ctx['uid'], $sub !== '' ? $sub : (string) $valor(0));

        case 'time':
            if (strtolower($sub) === 'until') return $usaPadrao(chat_ate((string) $valor(0)));
            return chat_hora($sub !== '' ? $sub : (string) $valor(0));

        case 'math':
            return chat_conta(implode(' ', $todos()));

        case 'repeat': {
            $vezes = trim((string) $valor(0));
            if (!preg_match('/^\d+$/', $vezes) || (int) $vezes < 1) throw new ChatSilencio();
            $frase = (string) $valor(1);
            $saida = [];
            for ($i = 0; $i < min(100, (int) $vezes); $i++) {
                $saida[] = $frase;
                if (strlen(implode(' ', $saida)) > 1000) break;
            }
            return implode(' ', $saida);
        }

        case 'queryescape':
        case 'queryencode': {
            $t = $valor(0);
            return $t === null ? '' : urlencode($t);
        }

        case 'pathescape': {
            $t = $valor(0);
            return $t === null ? '' : rawurlencode($t);
        }

        case 'customapi':
        case 'urlfetch': {
            $url = $sub !== '' ? $sub . implode('', $todos()) : implode('', $todos());
            if (++$estado['buscas'] > CHAT_BUSCAS) return '';
            return chat_busca($url);
        }
    }

    /* Pontos, IA, clima, emotes, loja: não existem aqui. */
    return $usaPadrao('');
}

/** A palavra N da mensagem (0 é o próprio comando). */
function chat_palavra(array $ctx, int $n): string
{
    $palavras = preg_split('/\s+/u', trim((string) ($ctx['mensagem'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    return (string) ($palavras[$n] ?? '');
}

function chat_palavras(array $m, array $ctx): string
{
    $palavras = preg_split('/\s+/u', trim((string) ($ctx['mensagem'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    $de  = $m[1] === '' ? 0 : (int) $m[1];
    $ate = $m[2] === '' ? $de : ($m[3] === '' ? count($palavras) - 1 : (int) $m[3]);
    if ($ate < $de) return '';
    $trecho = array_slice($palavras, $de, $ate - $de + 1);

    /* Barra e ponto no começo viram comando de chat na mão de quem digita. */
    $trecho = array_map(fn($p) => ltrim($p, '/.'), $trecho);
    $texto = trim(implode(' ', $trecho));

    $filtro = strtolower($m[4] ?? '');
    if (($filtro === 'username' || $filtro === 'word') && !preg_match('/^@?\w{1,30}$/u', $texto)) return '';
    return $texto;
}

/** $(sender), $(sender.name), $(user.level)... */
function chat_pessoa(string $campo, string $nome, string $login, array $ctx, bool $proprio): string
{
    switch (strtolower($campo)) {
        case '':
            return $nome;
        case 'name':
            return strtolower($login);
        case 'level':
            return (string) ($proprio ? (CHAT_NIVEL[$ctx['cargo'] ?? 'chat'] ?? 100) : 100);
        case 'twitchid':
            return $proprio ? (string) ($ctx['id_twitch'] ?? '') : '';
    }
    /* Pontos, tempo assistido, última mensagem: coisas do StreamElements. */
    return '';
}

/* ------------------------------------------------------------------ *
 *  Canal
 * ------------------------------------------------------------------ */

/**
 * O canal: nome e id sempre; título, categoria e se está ao vivo só quando
 * $vivo pede, porque são duas perguntas a mais à Twitch.
 */
function chat_canal(int $uid, string $outro = '', bool $vivo = false): ?array
{
    static $memo = [];
    $outro = strtolower(ltrim(trim($outro), '@'));
    $chave = $outro !== '' ? 'l:' . $outro : 'u:' . $uid;
    if (array_key_exists($chave, $memo)) {
        $c = $memo[$chave];
        if (!$c || !$vivo || isset($c['ok'])) return $c;
        return $memo[$chave] = chat_canal_vivo($c);
    }

    try {
        if ($outro !== '') {
            if (!preg_match('/^\w{1,25}$/', $outro)) return $memo[$chave] = null;
            [$h, $u] = tw_helix_app('GET', '/users', ['login' => $outro]);
            $u = $u['data'][0] ?? null;
            if ($h !== 200 || !$u) return $memo[$chave] = null;
            $c = ['id' => (string) $u['id'], 'login' => (string) $u['login'],
                  'nome' => (string) ($u['display_name'] ?: $u['login']), 'proprio' => false];
        } else {
            $st = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch();
            if (!$u) return $memo[$chave] = null;
            $c = ['id' => (string) $u['twitch_user_id'], 'login' => (string) $u['login'],
                  'nome' => (string) (($u['nome_exibicao'] ?? '') ?: $u['login']), 'proprio' => true];
        }
        $memo[$chave] = $c;
        return $vivo ? ($memo[$chave] = chat_canal_vivo($c)) : $c;
    } catch (Throwable $e) {
        return $memo[$chave] = null;
    }
}

function chat_canal_vivo(array $c): array
{
    try {
        [$h1, $ch] = tw_helix_app('GET', '/channels', ['broadcaster_id' => $c['id']]);
        $ch = $ch['data'][0] ?? [];
        [$h2, $st] = tw_helix_app('GET', '/streams', ['user_id' => $c['id'], 'first' => 1]);
        $st = $st['data'][0] ?? null;
    } catch (Throwable $e) {
        $h1 = $h2 = 0;
        $ch = [];
        $st = null;
    }
    $c['titulo']  = (string) ($ch['title'] ?? '');
    $c['jogo']    = (string) ($ch['game_name'] ?? '');
    $c['aovivo']  = $h2 === 200 && $st !== null;
    $c['viewers'] = $st ? (int) $st['viewer_count'] : 0;
    $c['desde']   = $st ? (int) strtotime((string) $st['started_at']) : 0;
    $c['ok']      = $h1 === 200;
    return $c;
}

function chat_canal_campo(int $uid, string $campo, string $outro): string
{
    if ($campo === 'provider') return 'twitch';
    $c = chat_canal($uid, $outro, in_array($campo, ['title', 'status', 'game', 'viewers', 'uptime'], true));
    if (!$c) return in_array($campo, ['title', 'status', 'game'], true) ? '<erro>' : ltrim($outro, '@');

    switch ($campo) {
        case 'display_name':
            return $c['nome'];
        case 'provider_id':
        case 'twitchid':
            return $c['id'];
        case 'title':
        case 'status':
            return $c['ok'] ? $c['titulo'] : '<erro>';
        case 'game':
            return $c['ok'] ? ($c['jogo'] !== '' ? $c['jogo'] : '<sem categoria>') : '<erro>';
        case 'viewers':
            return $c['aovivo'] ? (string) $c['viewers'] : '<fora do ar>';
        case 'uptime':
            return $c['aovivo'] ? chat_duracao(time() - $c['desde']) : '<fora do ar>';
        case 'followers':
        case 'subs':
        case 'subcount':
        case 'subscribers':
            if (!$c['proprio']) return '0';
            try {
                require_once __DIR__ . '/contagem.php';
                return (string) (contagem($uid, $campo === 'followers' ? 'seguidores' : 'subs') ?? 0);
            } catch (Throwable $e) {
                return '0';
            }
    }
    return $c['login'];
}

/** "2 h 5 min", do jeito que se fala. */
function chat_duracao(int $seg): string
{
    $seg = max(0, $seg);
    $d = intdiv($seg, 86400);
    $h = intdiv($seg % 86400, 3600);
    $m = intdiv($seg % 3600, 60);
    if ($d > 0) return $d . ($d === 1 ? ' dia' : ' dias') . ($h ? ' ' . $h . ' h' : '');
    if ($h > 0) return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    if ($m > 0) return $m . ' min';
    return $seg . ' s';
}

/* ------------------------------------------------------------------ *
 *  Sorteio, contador, hora e conta
 * ------------------------------------------------------------------ */

function chat_aleatorio(string $sub, array $pedacos, callable $valor, array $ctx): string
{
    if ($sub === 'pick') {
        $itens = [];
        foreach (array_keys($pedacos) as $i) $itens[] = $i;
        /* "$(random.pick 1,0)": sem espaço, a vírgula separa. */
        if (count($itens) === 1) {
            $unico = (string) $valor(0);
            if (strpos($unico, ',') !== false) {
                $partes = explode(',', $unico);
                return trim($partes[random_int(0, count($partes) - 1)]);
            }
            return $unico;
        }
        return $itens ? (string) $valor($itens[random_int(0, count($itens) - 1)]) : '';
    }

    if ($sub === 'chatter') {
        $gente = array_values(array_filter((array) ($ctx['chatters'] ?? []), 'is_string'));
        return $gente ? $gente[random_int(0, count($gente) - 1)] : (string) $ctx['quem'];
    }
    if ($sub === 'emote') return '';

    $faixa = $sub === 'number' || $sub === '' ? (string) $valor(0) : $sub;
    $de = 1;
    $ate = 100;
    if (preg_match('/^(-?\d+)\s*-\s*(-?\d+)$/', trim($faixa), $m)) {
        $de = (int) $m[1];
        $ate = (int) $m[2];
        if ($de > $ate) [$de, $ate] = [$ate, $de];
    }
    return (string) random_int($de, $ate);
}

function chat_contador_nome(string $nome): string
{
    return mb_substr(strtolower(trim(preg_replace('/[^\w.-]/u', '', $nome))), 0, 40);
}

/** $(count): muda o contador e devolve o número novo. Sem o SQL 053, vazio. */
function chat_contador(int $uid, string $nome, string $muda): string
{
    $nome = chat_contador_nome($nome);
    if ($nome === '') return '';
    $muda = trim($muda);
    try {
        if (preg_match('/^-?\d+$/', $muda) && $muda[0] !== '+' && strpos($muda, '-') !== 0) {
            db()->prepare(
                'INSERT INTO contadores (usuario_id, nome, valor) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
            )->execute([$uid, $nome, (int) $muda]);
        } else {
            $passo = preg_match('/^[+-]\d+$/', $muda) ? (int) $muda : 1;
            db()->prepare(
                'INSERT INTO contadores (usuario_id, nome, valor) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE valor = valor + VALUES(valor)'
            )->execute([$uid, $nome, $passo]);
        }
        return chat_contador_le($uid, $nome);
    } catch (Throwable $e) {
        return '';
    }
}

function chat_contador_le(int $uid, string $nome): string
{
    $nome = chat_contador_nome($nome);
    if ($nome === '') return '0';
    try {
        $st = db()->prepare('SELECT valor FROM contadores WHERE usuario_id = ? AND nome = ?');
        $st->execute([$uid, $nome]);
        return (string) (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return '0';
    }
}

/** $(time): HH:MM, em UTC se não disserem o fuso — igual ao StreamElements. */
function chat_hora(string $fuso): string
{
    $fuso = trim($fuso);
    try {
        $tz = new DateTimeZone(in_array($fuso, timezone_identifiers_list(), true) ? $fuso : 'UTC');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    return (new DateTimeImmutable('now', $tz))->format('H:i');
}

/** $(time.until 19:00): quanto falta, ou quanto passou. */
function chat_ate(string $quando): string
{
    $quando = trim($quando);
    $agora = time();
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $quando, $m)) {
        $alvo = gmmktime((int) $m[1], (int) $m[2], 0, (int) gmdate('n'), (int) gmdate('j'), (int) gmdate('Y'));
        if ($alvo < $agora) $alvo += 86400;
    } else {
        $alvo = strtotime($quando);
        if ($alvo === false || !preg_match('/^\d{4}-\d{2}-\d{2}T/', $quando)) return 'horário inválido';
    }
    $dif = $alvo - $agora;
    return $dif >= 0 ? chat_duracao($dif) : chat_duracao(-$dif) . ' atrás';
}

/**
 * $(math): as quatro operações, potência, resto, parênteses e algumas
 * funções. Sem eval: cada pedaço passa por uma gramática pequena.
 */
function chat_conta(string $expr): string
{
    $expr = strtolower(trim($expr));
    if ($expr === '' || strlen($expr) > 200) return 'conta inválida';
    preg_match_all('/\d+(?:\.\d+)?|[a-z]+|\*\*|[-+*\/%^(),]|\S/', $expr, $m);
    $fichas = $m[0];
    foreach ($fichas as $f) {
        if (!preg_match('/^(?:\d+(?:\.\d+)?|[a-z]+|\*\*|[-+*\/%^(),])$/', $f)) return 'conta inválida';
    }
    $i = 0;
    try {
        $v = chat_conta_soma($fichas, $i);
        if ($i !== count($fichas) || !is_finite($v)) return 'conta inválida';
    } catch (Throwable $e) {
        return 'conta inválida';
    }
    if (abs($v - round($v)) < 1e-9 && abs($v) < 1e15) return (string) (int) round($v);
    return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
}

function chat_conta_soma(array $f, int &$i): float
{
    $v = chat_conta_produto($f, $i);
    while (isset($f[$i]) && ($f[$i] === '+' || $f[$i] === '-')) {
        $op = $f[$i++];
        $d = chat_conta_produto($f, $i);
        $v = $op === '+' ? $v + $d : $v - $d;
    }
    return $v;
}

function chat_conta_produto(array $f, int &$i): float
{
    $v = chat_conta_potencia($f, $i);
    while (isset($f[$i]) && in_array($f[$i], ['*', '/', '%'], true)) {
        $op = $f[$i++];
        $d = chat_conta_potencia($f, $i);
        if ($op !== '*' && $d == 0) throw new RuntimeException('divisão por zero');
        $v = $op === '*' ? $v * $d : ($op === '/' ? $v / $d : fmod($v, $d));
    }
    return $v;
}

function chat_conta_potencia(array $f, int &$i): float
{
    $base = chat_conta_unario($f, $i);
    if (isset($f[$i]) && ($f[$i] === '^' || $f[$i] === '**')) {
        $i++;
        $exp = chat_conta_potencia($f, $i);
        if (abs($exp) > 1000) throw new RuntimeException('grande demais');
        return $base ** $exp;
    }
    return $base;
}

function chat_conta_unario(array $f, int &$i): float
{
    if (isset($f[$i]) && ($f[$i] === '-' || $f[$i] === '+')) {
        $op = $f[$i++];
        $v = chat_conta_unario($f, $i);
        return $op === '-' ? -$v : $v;
    }
    return chat_conta_atomo($f, $i);
}

function chat_conta_atomo(array $f, int &$i): float
{
    $t = $f[$i] ?? null;
    if ($t === null) throw new RuntimeException('faltou número');
    if (is_numeric($t)) { $i++; return (float) $t; }
    if ($t === '(') {
        $i++;
        $v = chat_conta_soma($f, $i);
        if (($f[$i] ?? '') !== ')') throw new RuntimeException('faltou )');
        $i++;
        return $v;
    }
    if ($t === 'pi') { $i++; return M_PI; }
    if ($t === 'e')  { $i++; return M_E; }

    $funcoes = ['round' => 1, 'floor' => 1, 'ceil' => 1, 'abs' => 1, 'sqrt' => 1, 'log' => 1,
                'sin' => 1, 'cos' => 1, 'tan' => 1, 'min' => 2, 'max' => 2, 'random' => 2];
    if (isset($funcoes[$t]) && ($f[$i + 1] ?? '') === '(') {
        $i += 2;
        $args = [];
        if (($f[$i] ?? '') !== ')') {
            $args[] = chat_conta_soma($f, $i);
            while (($f[$i] ?? '') === ',') { $i++; $args[] = chat_conta_soma($f, $i); }
        }
        if (($f[$i] ?? '') !== ')') throw new RuntimeException('faltou )');
        $i++;
        $a = $args[0] ?? 0.0;
        switch ($t) {
            case 'round': return round($a);
            case 'floor': return floor($a);
            case 'ceil':  return ceil($a);
            case 'abs':   return abs($a);
            case 'sqrt':  if ($a < 0) throw new RuntimeException('raiz negativa'); return sqrt($a);
            case 'log':   if ($a <= 0) throw new RuntimeException('log inválido'); return log($a);
            case 'sin':   return sin($a);
            case 'cos':   return cos($a);
            case 'tan':   return tan($a);
            case 'min':   return $args ? min($args) : 0.0;
            case 'max':   return $args ? max($args) : 0.0;
            case 'random':
                $de = (int) ($args[0] ?? 0);
                $ate = (int) ($args[1] ?? 1);
                if ($de > $ate) [$de, $ate] = [$ate, $de];
                return (float) random_int($de, $ate);
        }
    }
    throw new RuntimeException('não entendi ' . $t);
}

/* ------------------------------------------------------------------ *
 *  $(customapi): buscar um endereço sem abrir a porta de dentro
 * ------------------------------------------------------------------ */

/**
 * O começo da resposta de um endereço, pra ir no chat.
 *
 * QUEM ESCREVE O ENDEREÇO NÃO PODE ALCANÇAR A REDE DO SERVIDOR. O endereço
 * é resolvido aqui, cada IP é conferido (nada de 127.0.0.1, 10.x, 192.168.x
 * e companhia), só as portas de site passam, e a conexão vai presa no IP
 * conferido — senão um DNS que troca de resposta entre a conferência e a
 * conexão passaria por cima. Redirecionamento é seguido na mão, e cada
 * destino passa pela mesma conferência.
 */
function chat_busca(string $url): string
{
    $url = trim($url);
    $url = (string) preg_replace('/^(json|plain|text):/i', '', $url);
    if ($url === '') return 'endereço inválido';
    if (!preg_match('#^https?://#i', $url)) $url = 'http://' . $url;

    for ($pulo = 0; $pulo < 3; $pulo++) {
        $alvo = chat_url_segura($url);
        if (is_string($alvo)) return $alvo;

        $pedaco = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [$alvo['host'] . ':' . $alvo['porta'] . ':' . $alvo['ip']],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => false,
            /* Dez segundos: comando de RPG mexe no banco antes de responder,
               e o StreamElements esperava quinze. */
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'ZocaController/1.0 (+resposta de comando)',
            CURLOPT_HTTPHEADER     => ['Accept: text/plain, application/json;q=0.9, */*;q=0.5'],
            CURLOPT_WRITEFUNCTION  => function ($c, $dados) use (&$pedaco) {
                $pedaco .= $dados;
                /* Passou do que vai pro chat: devolver menos do que chegou
                   corta a conexão, e ninguém baixa um arquivo grande à toa. */
                return strlen($pedaco) > 4 * CHAT_API_BYTES ? 0 : strlen($dados);
            },
        ]);
        curl_exec($ch);
        $codigo  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $proximo = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $errno   = curl_errno($ch);
        curl_close($ch);

        if ($codigo >= 300 && $codigo < 400 && $proximo !== '') {
            $url = $proximo;
            continue;
        }
        if ($codigo === 0 && $errno !== CURLE_WRITE_ERROR) return 'não consegui falar com o endereço';
        if ($codigo >= 400) return 'o endereço respondeu ' . $codigo;

        $texto = trim((string) preg_replace('/\s+/u', ' ', mb_strcut($pedaco, 0, CHAT_API_BYTES, 'UTF-8')));
        return $texto !== '' ? $texto : 'o endereço não mandou nada';
    }
    return 'redirecionamento demais';
}

/** O host, a porta e o IP conferido; ou o texto do erro. */
function chat_url_segura(string $url)
{
    $p = parse_url($url);
    $esquema = strtolower((string) ($p['scheme'] ?? ''));
    $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
    if (!in_array($esquema, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) {
        return 'endereço inválido';
    }
    $porta = (int) ($p['port'] ?? ($esquema === 'https' ? 443 : 80));
    if (!in_array($porta, [80, 443, 8080, 8443], true)) return 'endereço não permitido';

    /* IP escrito de outro jeito ("2130706433", "0x7f.1") é 127.0.0.1 pra
       alguns resolvedores. Só vale IP no formato de sempre. */
    $numerico = true;
    foreach (explode('.', $host) as $rotulo) {
        if (!preg_match('/^(0x[0-9a-f]*|\d+)$/i', $rotulo)) { $numerico = false; break; }
    }
    if ($numerico && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return 'endereço não permitido';

    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return 'não consegui falar com o endereço';

    $bandeiras = defined('FILTER_FLAG_GLOBAL_RANGE')
        ? FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE
        : FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, $bandeiras)) return 'endereço não permitido';
    }
    /* O IP público da própria hospedagem passa: ele é compartilhado com
       outros sites (o zocahop.com entre eles), e chegar nele é chegar pela
       porta da frente, como qualquer visitante. */
    return ['host' => $host, 'porta' => $porta, 'ip' => $ips[0]];
}

/* ------------------------------------------------------------------ *
 *  O envio
 * ------------------------------------------------------------------ */

/** Os escopos que a pessoa deu na última entrada com a Twitch. */
function chat_escopos(int $uid): array
{
    $st = db()->prepare('SELECT tw_escopos FROM usuarios WHERE id = ?');
    $st->execute([$uid]);
    return preg_split('/\s+/', (string) $st->fetchColumn(), -1, PREG_SPLIT_NO_EMPTY);
}

/** Dá pra responder no chat desta conta? Pelo bot, ou pela própria conta. */
function chat_pode_responder(int $uid): bool
{
    $esc = chat_escopos($uid);
    return in_array('user:write:chat', $esc, true)
        || (chat_bot_id() !== '' && in_array('channel:bot', $esc, true));
}

/** O id da conta do bot, se ela já foi configurada. */
function chat_bot_id(): string
{
    return preg_match('/^\d+$/', (string) (cfg()['twitch_bot']['user_id'] ?? ''))
        ? (string) cfg()['twitch_bot']['user_id'] : '';
}

/**
 * O nome da conta do bot, pra tela poder dizer quem é que fala.
 *
 * Vem do config quando está lá; sem isso, pergunta pra Twitch pelo id. A
 * pergunta é barata porque esta função só é chamada quando alguém abre a
 * tela do bot — nunca no caminho de responder uma mensagem.
 */
function chat_bot_nome(): string
{
    static $nome = null;
    if ($nome !== null) return $nome;

    $nome = trim((string) (cfg()['twitch_bot']['login'] ?? ''));
    if ($nome !== '') return $nome;

    $id = chat_bot_id();
    if ($id === '') return $nome = '';

    try {
        [$http, $r] = tw_helix_app('GET', '/users', ['id' => $id]);
        if ($http === 200) $nome = (string) ($r['data'][0]['display_name'] ?? '');
    } catch (Throwable $e) { /* o nome é enfeite; o bot funciona sem ele */ }
    return $nome;
}

/**
 * Manda a mensagem no chat do canal.
 *
 * Com o bot configurado e o canal tendo dado a permissão channel:bot, sai
 * pelo bot (token do aplicativo). Sem isso, sai pela própria conta de quem
 * transmite, que precisa ter dado user:write:chat.
 *
 * $modo: 'say' (normal), 'reply' (responde a mensagem $msgId) ou 'mention'
 * (começa com @$login).
 */
function chat_enviar(int $uid, string $texto, string $modo = 'say', string $msgId = '', string $login = ''): array
{
    if ($modo === 'mention' && preg_match('/^\w{1,25}$/', $login) && stripos($texto, '@' . $login) !== 0) {
        $texto = '@' . $login . ' ' . $texto;
    }
    $texto = chat_limpa($texto);
    if ($texto === '') return ['ok' => false, 'erro' => 'A resposta ficou vazia.'];

    $canal = tw_broadcaster_id($uid);
    $corpo = ['broadcaster_id' => $canal, 'message' => $texto];
    if ($modo === 'reply' && preg_match('/^[0-9a-f-]{36}$/i', $msgId)) {
        $corpo['reply_parent_message_id'] = $msgId;
    }

    $esc = chat_escopos($uid);
    $bot = chat_bot_id();
    $tentativas = [];
    if ($bot !== '' && in_array('channel:bot', $esc, true)) $tentativas[] = 'bot';
    if (in_array('user:write:chat', $esc, true)) $tentativas[] = 'conta';
    if (!$tentativas) {
        return ['ok' => false, 'erro' => 'Falta a permissão de escrever no chat. Entre com a Twitch de novo pra liberar.'];
    }

    $ultimo = '';
    foreach ($tentativas as $jeito) {
        try {
            if ($jeito === 'bot') {
                [$http, $r] = tw_helix_app('POST', '/chat/messages', [], $corpo + ['sender_id' => $bot]);
            } else {
                [$http, $r] = tw_helix($uid, 'POST', '/chat/messages', [], $corpo + ['sender_id' => $canal]);
            }
        } catch (Throwable $e) {
            $ultimo = erro_publico($e);
            continue;
        }

        $item = $r['data'][0] ?? null;
        if ($http === 200 && !empty($item['is_sent'])) {
            return ['ok' => true, 'texto' => $texto, 'por' => $jeito];
        }
        if ($http === 200 && $item) {
            /* A Twitch recebeu e segurou: AutoMod, modo de seguidores, link
               bloqueado. Não adianta tentar pelo outro jeito. */
            return ['ok' => false, 'erro' => 'A Twitch segurou a mensagem: '
                . (string) ($item['drop_reason']['message'] ?? 'sem motivo informado')];
        }
        $ultimo = $http === 401 || $http === 403
            ? 'A Twitch recusou a permissão de escrever no chat. Entre com a Twitch de novo.'
            : ($http === 429 ? 'Mensagens demais seguidas. Espere um pouco.' : 'A Twitch respondeu ' . $http . '.');
    }
    return ['ok' => false, 'erro' => $ultimo];
}

/**
 * Guarda o que o bot acabou de falar, pra aparecer na tela dele.
 *
 * "Está ligado" é promessa; uma lista do que ele acabou de dizer é prova —
 * e é o que responde, sem adivinhação, se o comando não saiu por causa do
 * comando, da permissão ou da Twitch. Por isso a falha entra aqui também.
 *
 * Nada aqui pode derrubar uma resposta: a tabela pode não existir ainda, e
 * anotar é o menos importante que acontece nesta requisição.
 */
function chat_anota(int $uid, array $f): void
{
    try {
        db()->prepare(
            'INSERT INTO bot_falas (usuario_id, texto, comando, quem, origem, erro)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $uid,
            mb_substr((string) ($f['texto'] ?? ''), 0, 500),
            mb_substr((string) ($f['comando'] ?? ''), 0, 40),
            mb_substr((string) ($f['quem'] ?? ''), 0, 40),
            (string) ($f['origem'] ?? ''),
            mb_substr((string) ($f['erro'] ?? ''), 0, 160),
        ]);
    } catch (Throwable $e) { /* sem o SQL 056 o bot fala igual */ }
}

/** As últimas falas, e a faxina do resto. */
function chat_falas(int $uid, int $quantas = 20): array
{
    try {
        $st = db()->prepare(
            'SELECT texto, comando, quem, origem, erro, criado_em
               FROM bot_falas WHERE usuario_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $quantas))
        );
        $st->execute([$uid]);
        $falas = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    /* A FAXINA É AQUI, E NÃO NA HORA DE FALAR.

       Apagar a cada mensagem seria uma consulta a mais no caminho mais
       quente do sistema. Aqui é uma vez por visita ao painel, que é raro —
       e o efeito é o mesmo: a tabela nunca cresce. */
    try {
        $st = db()->prepare('SELECT id FROM bot_falas WHERE usuario_id = ? ORDER BY id DESC LIMIT 1 OFFSET 50');
        $st->execute([$uid]);
        $corte = $st->fetchColumn();
        if ($corte) {
            db()->prepare('DELETE FROM bot_falas WHERE usuario_id = ? AND id <= ?')->execute([$uid, (int) $corte]);
        }
    } catch (Throwable $e) { /* a lista já saiu */ }

    return $falas;
}
