<?php
/**
 * Comandos que a pessoa inventa.
 *
 * Um comando novo NÃO é código novo: é um apelido pra uma ação que a ponte já
 * sabe fazer, com um argumento já preenchido e uma regra própria de quem pode
 * e de quanto tempo esperar. "!brb" vira "cena Cenário BRB".
 *
 * É de propósito que não dê pra inventar a AÇÃO: se o chat pudesse definir o
 * que a ponte executa, o chat controlaria o OBS sem limite. As ações são as
 * que existem no código, e ponto. A "responder" é a única que leva texto
 * livre, e ele só vai pro chat — quem o envia é o servidor (responder.php).
 *
 * A ponte lê esta lista com a chave pública dela; o painel é quem escreve.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/chat.php';

cors();

/* Espelha o registro da ponte. Se as duas listas divergirem, um comando
   apontaria pro nada — então o servidor recusa o que a ponte não conhece. */
/* O QUE PODE DISPARAR UM GATILHO.
   Os mesmos nomes que a tabela de eventos usa, com 'presente' separado de
   'sub': quem quer festa quando alguém dá dez subs raramente quer a mesma
   festa a cada sub avulso. */
const EVENTOS_VALIDOS = ['sub', 'presente', 'bits', 'follow', 'real', 'raid'];
const GATILHOS_MAX = 20;

const ACOES_VALIDAS = [
    'panico', 'voltar', 'mute', 'cena', 'replay', 'camera', 'som', 'fonte',
    'aposta', 'fechar', 'cancelar', 'ganhou', 'marcar', 'titulo', 'categoria',
    'pular', 'like', 'adicionar', 'fila', 'musica', 'playlist', 'vod', 'luz',
    'responder',
];
const QUEM_VALIDO = ['chat', 'sub', 'vip', 'mod', 'supermod', 'dono'];
/* Cem, e não quarenta: quem vem do StreamElements chega com dezenas de
   comandos de resposta, e deixar metade pra trás não é importar. */
/* Teto de emergência, e não o do plano: mesmo no Pro ninguém precisa de
   mais que isto, e um número aqui evita que um laço maluco encha a tabela.
   Quem manda é o limite() do plano. */
const COMANDOS_MAX = 300;

/* Recados de tempo em tempo. Dez já é mais do que qualquer chat aguenta. */
const RECADOS_MAX = 10;
const RECADO_MENSAGENS = 8;

/** O SQL 053 já rodou? Ele traz apelidos, espera por pessoa e ligado. */
function comandos_053(): bool
{
    static $tem = null;
    if ($tem === null) {
        try {
            $tem = (bool) db()->query("SHOW COLUMNS FROM comandos LIKE 'ligado'")->fetch();
        } catch (Throwable $e) {
            $tem = false;
        }
    }
    return $tem;
}

/** O nome do jeito que o chat digita: minúsculo, sem "!", até 30 letras. */
function comando_nome(string $s): string
{
    $s = mb_strtolower(trim(ltrim(trim($s), '!')));
    $s = (string) preg_replace('/[^\p{L}\p{N}_-]/u', '', $s);
    return mb_strlen($s) <= (comandos_053() ? 30 : 20) ? $s : '';
}

$quem = quem_chama();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    /* A ponte também lê daqui: ela é 'painel' quando roda com a chave do dono. */
    /* SELECT *: as colunas do SQL 053 chegam depois, e a ponte de quem
       ainda não rodou ele não pode ficar sem comando nenhum. */
    $st = db()->prepare('SELECT * FROM comandos WHERE usuario_id = ? ORDER BY nome');
    $st->execute([$quem['usuario_id']]);
    $daPonte = !empty($_GET['ponte']);

    /* A ponte le a lista de supermods daqui junto com os comandos: e uma
       requisicao so, e ela ja faz essa de qualquer jeito. */
    $sm = db()->prepare('SELECT supermods FROM usuarios WHERE id = ?');
    $sm->execute([$quem['usuario_id']]);

    /* Quem tem passos usa passos; quem nao tem vira uma lista de um passo.
       Assim quem le — a ponte e a tela — enxerga UM formato so, em vez de
       cada um ter que lembrar do jeito antigo. */
    $lista = array_map(function (array $c) use ($daPonte): array {
        $p = $c['passos'] ? json_decode($c['passos'], true) : null;
        $passos = is_array($p) && $p
            ? $p
            : [['acao' => $c['acao'], 'argumento' => $c['argumento']]];
        /* A ponte não precisa do texto das respostas: quem lê ele é o
           servidor, na hora de mandar. Cem respostas longas a cada leitura
           seriam peso à toa em toda fonte do OBS. */
        if ($daPonte) {
            foreach ($passos as &$passo) {
                if (($passo['acao'] ?? '') === 'responder') $passo['argumento'] = null;
            }
            unset($passo);
        }
        return [
            'id'            => (int) $c['id'],
            'nome'          => (string) $c['nome'],
            'acao'          => (string) $c['acao'],
            'argumento'     => $daPonte ? null : $c['argumento'],
            'quem'          => (string) $c['quem'],
            'espera'        => (int) $c['espera'],
            'passos'        => $passos,
            'apelidos'      => (string) ($c['apelidos'] ?? ''),
            'espera_pessoa' => (int) ($c['espera_pessoa'] ?? 0),
            'ligado'        => (int) ($c['ligado'] ?? 1),
            'origem'        => (string) ($c['origem'] ?? ''),
        ];
    }, $st->fetchAll());

    /* Os gatilhos vêm na MESMA resposta dos comandos. A ponte já faz essa
       requisição; fazer outra só pra isso seria uma consulta a mais em toda
       fonte do OBS aberta, pra sempre. */
    $g = db()->prepare(
        'SELECT id, evento, minimo, espera, ligado, passos FROM gatilhos
          WHERE usuario_id = ? ORDER BY id'
    );
    $g->execute([$quem['usuario_id']]);
    $gatilhos = array_map(function (array $x): array {
        $p = $x['passos'] ? json_decode($x['passos'], true) : null;
        $x['passos'] = is_array($p) ? $p : [];
        $x['minimo'] = (int) $x['minimo'];
        $x['espera'] = (int) $x['espera'];
        $x['ligado'] = (int) $x['ligado'];
        return $x;
    }, $g->fetchAll());

    /* Os recados vêm na mesma resposta: a ponte já faz esta requisição. */
    $recados = [];
    try {
        $rc = db()->prepare('SELECT * FROM recados WHERE usuario_id = ? ORDER BY id');
        $rc->execute([$quem['usuario_id']]);
        foreach ($rc->fetchAll() as $r) {
            $msgs = json_decode((string) $r['mensagens'], true);
            $recados[] = [
                'id'         => (int) $r['id'],
                'nome'       => (string) $r['nome'],
                'mensagens'  => $daPonte ? [] : (is_array($msgs) ? $msgs : []),
                'quantas'    => is_array($msgs) ? count($msgs) : 0,
                'minutos'    => (int) $r['minutos'],
                'linhas'     => (int) $r['linhas'],
                'no_ar'      => (int) $r['no_ar'],
                'fora_do_ar' => (int) $r['fora_do_ar'],
                'ligado'     => (int) $r['ligado'],
            ];
        }
    } catch (Throwable $e) { /* sem o SQL 054, ninguém tem recado */ }

    /* Com o bot na nuvem no chat, quem responde é o servidor. A ponte
       precisa saber pra não responder também: sairia em dobro. */
    $nuvem = false;
    try {
        $bn = db()->prepare('SELECT ligado FROM bot_chat WHERE usuario_id = ?');
        $bn->execute([$quem['usuario_id']]);
        $nuvem = (bool) $bn->fetchColumn();
    } catch (Throwable $e) { /* sem o SQL 055, ninguém tem bot na nuvem */ }

    /* OS DE FÁBRICA QUE ESTÃO DESLIGADOS.

       Guardamos os desligados, não os ligados: comando de fábrica novo
       entra funcionando pra todo mundo. Ao contrário, cada um que a gente
       criasse nasceria morto pra quem já usa o site. */
    $desligados = [];
    try {
        $eo = db()->prepare('SELECT embutidos_off FROM usuarios WHERE id = ?');
        $eo->execute([$quem['usuario_id']]);
        $desligados = preg_split('/\s+/', (string) $eo->fetchColumn(), -1, PREG_SPLIT_NO_EMPTY);
    } catch (Throwable $e) { /* sem o SQL 060, nada está desligado */ }

    $saida = [
        'nuvem'         => $nuvem,
        'embutidos_off' => $desligados,
        'comandos'  => $lista,
        'gatilhos'  => $gatilhos,
        'recados'   => $recados,
        'acoes'     => ACOES_VALIDAS,
        'eventos'   => EVENTOS_VALIDOS,
        'supermods' => (string) ($sm->fetchColumn() ?: ''),
        'max'       => COMANDOS_MAX,
    ];
    if (!$daPonte && $quem['tipo'] === 'painel') {
        /* Pra tela avisar antes: sem a permissão, o comando existe mas a
           resposta não sai. */
        $saida['pode_responder'] = chat_pode_responder((int) $quem['usuario_id']);
        $saida['completo'] = comandos_053();
    }
    json_saida($saida);
}

if ($quem['tipo'] !== 'painel') {
    json_saida(['erro' => 'Só o dono do canal mexe nos comandos.'], 403);
}

$d = corpo_json();
$acao = (string) ($d['acao'] ?? '');
trava('comandos', 60, 60);

if ($acao === 'supermods') {
    /* Logins da Twitch, separados por virgula ou espaco. Guardo em minusculas
       porque e assim que a etiqueta do chat chega. */
    $lista = preg_split('/[,\s]+/', mb_strtolower(trim((string) ($d['lista'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);
    $lista = array_slice(array_unique(array_filter($lista, function ($n) {
        return preg_match('/^[a-z0-9_]{2,25}$/', $n);
    })), 0, 40);

    db()->prepare('UPDATE usuarios SET supermods = ? WHERE id = ?')
        ->execute([implode(',', $lista) ?: null, $quem['usuario_id']]);

    json_saida(['ok' => true, 'supermods' => implode(', ', $lista)]);
}

/* ---------- gatilhos: quando acontecer X, faça Y ---------- */
if ($acao === 'gatilho' || $acao === 'gatilho-apagar') {
    if ($acao === 'gatilho-apagar') {
        $st = db()->prepare('DELETE FROM gatilhos WHERE id = ? AND usuario_id = ?');
        $st->execute([(int) ($d['id'] ?? 0), $quem['usuario_id']]);
        json_saida(['ok' => (bool) $st->rowCount()]);
    }

    $evento = (string) ($d['evento'] ?? '');
    if (!in_array($evento, EVENTOS_VALIDOS, true)) {
        json_saida(['erro' => 'Esse evento não existe.'], 400);
    }

    $passos = [];
    foreach ((array) ($d['passos'] ?? []) as $passo) {
        $a = strtolower(trim((string) ($passo['acao'] ?? '')));
        if (!in_array($a, ACOES_VALIDAS, true)) continue;
        $passos[] = [
            'acao'      => $a,
            'argumento' => mb_substr(trim((string) ($passo['argumento'] ?? '')), 0, $a === 'responder' ? 500 : 100),
        ];
        if (count($passos) >= 8) break;
    }
    if (!$passos) json_saida(['erro' => 'Escolha pelo menos uma ação pro gatilho fazer.'], 400);
    foreach ($passos as $passo) {
        if ($passo['acao'] === 'responder' && $passo['argumento'] === '') {
            json_saida(['erro' => 'A resposta do gatilho está vazia.'], 400);
        }
    }

    /* NÃO EXISTE CARGO AQUI: quem "digita" é o evento, e evento não tem
       moderação. Por isso as ações fortes ficam de fora — um gatilho de
       pânico no follow seria uma live derrubada por qualquer um que clicasse
       em seguir. */
    foreach ($passos as $passo) {
        if (in_array($passo['acao'], ['panico', 'voltar', 'ganhou'], true)) {
            json_saida(['erro' => 'O "' . $passo['acao'] . '" não pode ser disparado por evento — só por gente.'], 400);
        }
    }

    $minimo = max(1, min(1000000, (int) ($d['minimo'] ?? 1)));
    $espera = max(0, min(3600, (int) ($d['espera'] ?? 10)));
    $ligado = empty($d['ligado']) ? 0 : 1;
    $json   = json_encode($passos, JSON_UNESCAPED_UNICODE);

    $id = (int) ($d['id'] ?? 0);
    if ($id > 0) {
        db()->prepare(
            'UPDATE gatilhos SET evento = ?, minimo = ?, espera = ?, ligado = ?, passos = ?
              WHERE id = ? AND usuario_id = ?'
        )->execute([$evento, $minimo, $espera, $ligado, $json, $id, $quem['usuario_id']]);
        json_saida(['ok' => true, 'id' => $id]);
    }

    $st = db()->prepare('SELECT COUNT(*) FROM gatilhos WHERE usuario_id = ?');
    $st->execute([$quem['usuario_id']]);
    if ((int) $st->fetchColumn() >= GATILHOS_MAX) {
        json_saida(['erro' => 'Você já tem ' . GATILHOS_MAX . ' gatilhos.'], 400);
    }

    db()->prepare(
        'INSERT INTO gatilhos (usuario_id, evento, minimo, espera, ligado, passos)
              VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$quem['usuario_id'], $evento, $minimo, $espera, $ligado, $json]);
    json_saida(['ok' => true, 'id' => (int) db()->lastInsertId()]);
}

/* ---------- ligar e desligar na própria lista ---------- */
if ($acao === 'ligar') {
    $tabelas = ['comando' => 'comandos', 'recado' => 'recados', 'gatilho' => 'gatilhos'];
    $tabela = $tabelas[(string) ($d['tipo'] ?? '')] ?? '';
    if ($tabela === '') json_saida(['erro' => 'Não sei o que ligar.'], 400);

    try {
        $st = db()->prepare("UPDATE $tabela SET ligado = ? WHERE id = ? AND usuario_id = ?");
        $st->execute([empty($d['ligado']) ? 0 : 1, (int) ($d['id'] ?? 0), (int) $quem['usuario_id']]);
        json_saida(['ok' => (bool) $st->rowCount()]);
    } catch (PDOException $e) {
        json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 053 no banco.')], 500);
    }
}

/* ---------- ligar e desligar um de fábrica ---------- */
if ($acao === 'embutido') {
    $nome = mb_strtolower(trim((string) ($d['nome'] ?? '')));

    /* Só nome que a ponte conhece: guardar um inventado encheria a coluna
       de lixo que nunca mais sai. E o 'voltar' nunca desliga — é a saída
       do pânico, e desligar a saída é ficar preso dentro. */
    if (!in_array($nome, ACOES_VALIDAS, true) || $nome === 'voltar') {
        json_saida(['erro' => 'Esse comando não desliga.'], 400);
    }

    $uid = (int) $quem['usuario_id'];
    try {
        $st = db()->prepare('SELECT embutidos_off FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $lista = preg_split('/\s+/', (string) $st->fetchColumn(), -1, PREG_SPLIT_NO_EMPTY);

        $lista = array_values(array_diff($lista, [$nome]));
        if (empty($d['ligado'])) $lista[] = $nome;

        db()->prepare('UPDATE usuarios SET embutidos_off = ? WHERE id = ?')
            ->execute([mb_substr(implode(' ', $lista), 0, 400), $uid]);
    } catch (PDOException $e) {
        json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 060 no banco.')], 500);
    }

    json_saida(['ok' => true, 'embutidos_off' => $lista]);
}

/* ---------- recados de tempo em tempo ---------- */
if ($acao === 'recado' || $acao === 'recado-apagar') {
    $uid = (int) $quem['usuario_id'];

    if ($acao === 'recado-apagar') {
        $st = db()->prepare('DELETE FROM recados WHERE id = ? AND usuario_id = ?');
        $st->execute([(int) ($d['id'] ?? 0), $uid]);
        json_saida(['ok' => (bool) $st->rowCount()]);
    }

    $mensagens = [];
    foreach ((array) ($d['mensagens'] ?? []) as $msg) {
        $msg = mb_substr(trim((string) $msg), 0, 500);
        if ($msg !== '') $mensagens[] = $msg;
        if (count($mensagens) >= RECADO_MENSAGENS) break;
    }
    if (!$mensagens) json_saida(['erro' => 'Escreva pelo menos uma mensagem pro recado.'], 400);

    $noAr    = empty($d['no_ar']) ? 0 : 1;
    $foraDoAr = empty($d['fora_do_ar']) ? 0 : 1;
    if (!$noAr && !$foraDoAr) {
        json_saida(['erro' => 'Escolha quando o recado vale: com a live no ar, fora do ar, ou nos dois.'], 400);
    }

    $campos = [
        'nome'       => mb_substr(trim((string) ($d['nome'] ?? '')), 0, 40),
        'mensagens'  => json_encode($mensagens, JSON_UNESCAPED_UNICODE),
        'minutos'    => max(1, min(1440, (int) ($d['minutos'] ?? 15))),
        'linhas'     => max(0, min(500, (int) ($d['linhas'] ?? 3))),
        'no_ar'      => $noAr,
        'fora_do_ar' => $foraDoAr,
        'ligado'     => array_key_exists('ligado', $d) && empty($d['ligado']) ? 0 : 1,
    ];

    try {
        $id = (int) ($d['id'] ?? 0);
        if ($id > 0) {
            db()->prepare(
                'UPDATE recados SET nome = ?, mensagens = ?, minutos = ?, linhas = ?,
                        no_ar = ?, fora_do_ar = ?, ligado = ?
                  WHERE id = ? AND usuario_id = ?'
            )->execute([...array_values($campos), $id, $uid]);
            json_saida(['ok' => true, 'id' => $id]);
        }

        $st = db()->prepare('SELECT COUNT(*) FROM recados WHERE usuario_id = ?');
        $st->execute([$uid]);
        if ((int) $st->fetchColumn() >= RECADOS_MAX) {
            json_saida(['erro' => 'Você já tem ' . RECADOS_MAX . ' recados.'], 400);
        }

        db()->prepare(
            'INSERT INTO recados (usuario_id, nome, mensagens, minutos, linhas, no_ar, fora_do_ar, ligado)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uid, ...array_values($campos)]);
        json_saida(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    } catch (PDOException $e) {
        json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 054 no banco.')], 500);
    }
}

if ($acao === 'apagar') {
    $id = (int) ($d['id'] ?? 0);
    $st = db()->prepare('DELETE FROM comandos WHERE id = ? AND usuario_id = ?');
    $st->execute([$id, $quem['usuario_id']]);
    json_saida(['ok' => (bool) $st->rowCount()]);
}

/**
 * Um comando do jeito de gravar, ou o texto do erro.
 *
 * Continua valendo a regra que importa: a AÇÃO não é livre. Se o chat
 * pudesse definir o que a ponte executa, ele controlaria o OBS sem limite —
 * então cada passo tem que ser uma das ações do código.
 */
function comando_limpo(array $d)
{
    /* O nome vai virar "!alguma-coisa" no chat: só o que dá pra digitar sem
       surpresa, e sem o prefixo — quem põe o "!" é a ponte. */
    $nome = comando_nome((string) ($d['nome'] ?? ''));
    if ($nome === '') {
        return 'Escolha um nome só com letras, números, - e _ (até ' . (comandos_053() ? 30 : 20) . ').';
    }
    if (in_array($nome, ACOES_VALIDAS, true)) {
        return 'Já existe um comando de fábrica chamado !' . $nome . '. Escolha outro nome.';
    }

    $passos = [];
    foreach ((array) ($d['passos'] ?? []) as $passo) {
        if (!is_array($passo)) continue;
        $a = (string) ($passo['acao'] ?? '');
        if (!in_array($a, ACOES_VALIDAS, true)) continue;
        $item = [
            'acao'      => $a,
            'argumento' => mb_substr(trim((string) ($passo['argumento'] ?? '')), 0, 500) ?: null,
        ];
        if ($a === 'responder') {
            if ($item['argumento'] === null) return 'A resposta do !' . $nome . ' está vazia.';
            $item['modo'] = in_array($passo['modo'] ?? '', ['reply', 'mention'], true) ? $passo['modo'] : 'say';
        }
        $passos[] = $item;
        if (count($passos) >= 8) break;   /* uma corrente longa demais vira bagunça */
    }

    /* Sem passos explícitos, aceito o formato antigo de um só. */
    $destino = (string) ($d['destino'] ?? '');
    if (!$passos && in_array($destino, ACOES_VALIDAS, true) && $destino !== 'responder') {
        $passos[] = [
            'acao'      => $destino,
            'argumento' => mb_substr(trim((string) ($d['argumento'] ?? '')), 0, 500) ?: null,
        ];
    }
    if (!$passos) return 'Escolha pelo menos uma ação pro comando fazer.';

    $quemPode = (string) ($d['quem'] ?? 'mod');
    if (!in_array($quemPode, QUEM_VALIDO, true)) $quemPode = 'mod';

    /* Pânico e voltar mexem na live inteira: liberar pro chat seria entregar o
       botão de desligar pra qualquer um que entrasse. Vale pra QUALQUER passo,
       não só pro primeiro: senão bastaria pôr o pânico em segundo lugar pra
       escapar da regra. */
    foreach ($passos as $passo) {
        if (in_array($passo['acao'], ['panico', 'voltar', 'ganhou'], true)
            && in_array($quemPode, ['chat', 'sub', 'vip'], true)) {
            return 'O "' . $passo['acao'] . '" é forte demais pra liberar fora da moderação.';
        }
    }

    /* Outros nomes pro mesmo comando: !links e !redes. */
    $apelidos = [];
    foreach (preg_split('/[\s,]+/', (string) ($d['apelidos'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $a) {
        $a = comando_nome($a);
        if ($a === '' || $a === $nome || in_array($a, ACOES_VALIDAS, true) || in_array($a, $apelidos, true)) continue;
        $apelidos[] = $a;
        if (count($apelidos) >= 10) break;
    }

    return [
        'nome'          => $nome,
        'passos'        => $passos,
        'quem'          => $quemPode,
        'espera'        => max(1, min(3600, (int) ($d['espera'] ?? 5))),
        'espera_pessoa' => max(0, min(3600, (int) ($d['espera_pessoa'] ?? 0))),
        'ligado'        => array_key_exists('ligado', $d) && empty($d['ligado']) ? 0 : 1,
        'apelidos'      => $apelidos ? mb_substr(implode(' ', $apelidos), 0, 255) : null,
    ];
}

/** Grava um comando limpo. Devolve o id, ou -1 se a conta já está no limite. */
function comando_grava(int $uid, array $c, int $id, ?string $origem): int
{
    $json = json_encode($c['passos'], JSON_UNESCAPED_UNICODE);
    $acao = $c['passos'][0]['acao'];
    $arg  = $c['passos'][0]['argumento'];

    if ($id > 0) {
        if (comandos_053()) {
            db()->prepare(
                'UPDATE comandos SET nome = ?, acao = ?, argumento = ?, quem = ?, espera = ?, passos = ?,
                        apelidos = ?, espera_pessoa = ?, ligado = ?
                  WHERE id = ? AND usuario_id = ?'
            )->execute([$c['nome'], $acao, $arg, $c['quem'], $c['espera'], $json,
                        $c['apelidos'], $c['espera_pessoa'], $c['ligado'], $id, $uid]);
        } else {
            db()->prepare(
                'UPDATE comandos SET nome = ?, acao = ?, argumento = ?, quem = ?, espera = ?, passos = ?
                  WHERE id = ? AND usuario_id = ?'
            )->execute([$c['nome'], $acao, $arg, $c['quem'], $c['espera'], $json, $id, $uid]);
        }
        return $id;
    }

    $st = db()->prepare('SELECT COUNT(*) FROM comandos WHERE usuario_id = ?');
    $st->execute([$uid]);
    $tem = (int) $st->fetchColumn();
    if ($tem >= min(COMANDOS_MAX, limite($usuario_id, 'comandos_max', COMANDOS_MAX))) return -1;

    if (comandos_053()) {
        db()->prepare(
            'INSERT INTO comandos (usuario_id, nome, acao, argumento, quem, espera, passos,
                                   apelidos, espera_pessoa, ligado, origem)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uid, $c['nome'], $acao, $arg, $c['quem'], $c['espera'], $json,
                    $c['apelidos'], $c['espera_pessoa'], $c['ligado'], $origem]);
    } else {
        db()->prepare(
            'INSERT INTO comandos (usuario_id, nome, acao, argumento, quem, espera, passos)
                  VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$uid, $c['nome'], $acao, $arg, $c['quem'], $c['espera'], $json]);
    }
    return (int) db()->lastInsertId();
}

$uid = (int) $quem['usuario_id'];

/* ---------- trazer do StreamElements ----------

   Os comandos já chegam convertidos pelo navegador: a leitura do
   StreamElements acontece lá, e a chave de quem importa nunca passa por
   aqui. Cada um passa pela mesma conferência de quem cria um na mão. */
if ($acao === 'importar') {
    $lista = array_slice((array) ($d['comandos'] ?? []), 0, 200);
    $substituir = !empty($d['substituir']);

    $st = db()->prepare('SELECT id, nome FROM comandos WHERE usuario_id = ?');
    $st->execute([$uid]);
    $existentes = [];
    foreach ($st->fetchAll() as $l) $existentes[(string) $l['nome']] = (int) $l['id'];

    $criados = [];
    $trocados = [];
    $pulados = [];
    foreach ($lista as $item) {
        if (!is_array($item)) continue;
        $rotulo = mb_substr((string) ($item['nome'] ?? '?'), 0, 40);
        $c = comando_limpo($item);
        if (is_string($c)) {
            $pulados[] = ['nome' => $rotulo, 'motivo' => $c];
            continue;
        }
        $ja = $existentes[$c['nome']] ?? 0;
        if ($ja && !$substituir) {
            $pulados[] = ['nome' => $c['nome'], 'motivo' => 'você já tem um comando com esse nome'];
            continue;
        }
        try {
            $id = comando_grava($uid, $c, $ja, 'streamelements');
        } catch (PDOException $e) {
            $pulados[] = ['nome' => $c['nome'], 'motivo' => erro_publico($e, 'não consegui gravar')];
            continue;
        }
        if ($id === -1) {
            $pulados[] = ['nome' => $c['nome'], 'motivo' => 'chegou no limite de ' . COMANDOS_MAX . ' comandos'];
            continue;
        }
        $existentes[$c['nome']] = $id;
        if ($ja) $trocados[] = $c['nome']; else $criados[] = $c['nome'];

        /* O $(count) continua de onde parou lá. */
        if (isset($item['contador']) && is_numeric($item['contador'])) {
            chat_contador($uid, $c['nome'], (string) max(0, (int) $item['contador']));
        }
    }

    json_saida(['ok' => true, 'criados' => $criados, 'trocados' => $trocados, 'pulados' => $pulados]);
}

if ($acao !== 'salvar') {
    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

$c = comando_limpo($d);
if (is_string($c)) json_saida(['erro' => $c], 400);

try {
    $id = comando_grava($uid, $c, (int) ($d['id'] ?? 0), null);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') json_saida(['erro' => 'Você já tem um comando com esse nome.'], 400);
    throw $e;
}
if ($id === -1) json_saida(['erro' => 'Você já tem ' . COMANDOS_MAX . ' comandos.'], 400);

json_saida(['ok' => true, 'id' => $id]);
