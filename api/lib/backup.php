<?php
/**
 * O depósito dos backups do OBS, na parte que mais de um arquivo usa.
 *
 * Um backup passa por três coisas antes de virar arquivo: vira texto,
 * encolhe (gzip), e é cifrado. E volta na ordem contrária.
 *
 * CIFRADO POR QUÊ. Um backup de OBS carrega o endereço de toda fonte de
 * navegador da pessoa, e endereço de widget costuma ter um código dentro
 * que vale por uma senha. Um vazamento do banco não pode entregar isso de
 * bandeja. A chave mora no config.php, fora do banco.
 *
 * Sem a chave configurada o sistema continua funcionando e guarda só
 * comprimido — é pior, e a tela avisa, mas é melhor do que a pessoa achar
 * que tem backup e não ter.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/acesso.php';
require_once __DIR__ . '/cifra.php';

const BK_DIR    = __DIR__ . '/../../backups';
const BK_MAX    = 8;           /* versões por pessoa; a mais velha sai */
const BK_BYTES  = 8388608;     /* 8 MB de JSON cru — coleção gigante cabe */

function bk_caminho(string $arquivo): string
{
    return BK_DIR . '/' . basename($arquivo);
}

/** Texto cru -> o que vai pro disco. */
function bk_embrulha(string $cru): string
{
    $curto = gzencode($cru, 6);
    if ($curto === false) $curto = $cru;

    /* O prefixo diz como desembrulhar. O 'z0.' é o caso sem chave: some
       sozinho quando o config.php ganhar a chave e o backup for refeito. */
    return cifra_pronta() ? cifra($curto) : 'z0.' . base64_encode($curto);
}

/** O que está no disco -> texto cru. Null quando não dá pra abrir. */
function bk_desembrulha(string $guardado): ?string
{
    $curto = substr($guardado, 0, 3) === 'z0.'
        ? base64_decode(substr($guardado, 3), true)
        : decifra($guardado);

    if ($curto === false || $curto === null) return null;

    $cru = @gzdecode($curto);
    return $cru === false ? $curto : $cru;   /* gzencode pode ter falhado na hora de gravar */
}

/**
 * Guarda uma versão e devolve o id.
 *
 * Conteúdo igual ao da última versão não vira versão nova: quem abre o OBS
 * todo dia sem mexer em nada encheria as oito vagas com a mesma coisa e
 * perderia o retrato de antes da mudança — justamente o que ele quer.
 */
function bk_guarda(int $uid, string $cru, string $origem, array $sobre): array
{
    $impressao = hash('sha256', $cru);

    /* Só a ÚLTIMA conta. Comparar com todas daria errado em quem volta a
       uma montagem antiga: o retrato de agora seria igual a um velho, não
       viraria versão nova, e a mais recente da lista seria justamente a que
       a pessoa desfez. */
    $ja = db()->prepare('SELECT id, impressao FROM backups WHERE usuario_id = ? ORDER BY id DESC LIMIT 1');
    $ja->execute([$uid]);
    $ultima = $ja->fetch(PDO::FETCH_ASSOC);
    if ($ultima && hash_equals((string) $ultima['impressao'], $impressao)) {
        return ['ok' => true, 'id' => (int) $ultima['id'], 'repetido' => true];
    }

    if (!pasta_privada(BK_DIR)) {
        return ['ok' => false, 'erro' => 'Não consegui guardar o backup aqui no servidor.'];
    }

    $arquivo = bin2hex(random_bytes(16)) . '.bk';
    $conteudo = bk_embrulha($cru);
    if (@file_put_contents(bk_caminho($arquivo), $conteudo) === false) {
        return ['ok' => false, 'erro' => 'Não consegui guardar o backup aqui no servidor.'];
    }

    db()->prepare(
        'INSERT INTO backups (usuario_id, origem, arquivo, nome, bytes, cenas, fontes, impressao)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $uid, $origem, $arquivo,
        mb_substr(trim((string) ($sobre['nome'] ?? '')), 0, 120) ?: null,
        strlen($conteudo),
        max(0, min(9999, (int) ($sobre['cenas'] ?? 0))),
        max(0, min(9999, (int) ($sobre['fontes'] ?? 0))),
        $impressao,
    ]);

    bk_poda($uid);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

/** Tira as versões que passaram do teto, da mais velha pra mais nova. */
function bk_poda(int $uid): void
{
    $st = db()->prepare('SELECT id, arquivo FROM backups WHERE usuario_id = ? ORDER BY id DESC');
    $st->execute([$uid]);
    $todos = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach (array_slice($todos, BK_MAX) as $velho) {
        @unlink(bk_caminho((string) $velho['arquivo']));
        db()->prepare('DELETE FROM backups WHERE id = ?')->execute([(int) $velho['id']]);
    }
}

/** As versões de alguém, sem o conteúdo. */
function bk_lista(int $uid): array
{
    try {
        $st = db()->prepare(
            'SELECT id, origem, nome, bytes, cenas, fontes, criado_em,
                    TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS idade
               FROM backups WHERE usuario_id = ? ORDER BY id DESC'
        );
        $st->execute([$uid]);
    } catch (Throwable $e) {
        return [];      /* tabela ainda não criada */
    }

    return array_map(fn($b) => [
        'id'        => (int) $b['id'],
        'origem'    => (string) $b['origem'],
        'nome'      => (string) ($b['nome'] ?? ''),
        'bytes'     => (int) $b['bytes'],
        'cenas'     => (int) $b['cenas'],
        'fontes'    => (int) $b['fontes'],
        'criado_em' => (string) $b['criado_em'],
        /* A idade vem do banco: a tela mostra "há 3 h", e remontar a data
           aqui no fuso de quem olha erraria as horas. */
        'idade'     => (int) $b['idade'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}
