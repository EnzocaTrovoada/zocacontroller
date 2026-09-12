<?php
/**
 * Os avisos: quem recebe o quê, e por quê.
 *
 * Aviso nunca pode derrubar a ação que o gerou — curtir tem que funcionar
 * mesmo que a tabela de avisos ainda não exista no servidor. Por isso toda
 * escrita aqui é engolida em caso de erro.
 *
 * O texto é guardado cru e a tela escreve com textContent. Nada de HTML
 * montado aqui.
 */
require_once __DIR__ . '/db.php';

/** Rota só pra tela do próprio site; qualquer outra coisa vira nada. */
function notifica_rota(?string $rota): ?string
{
    if ($rota === null) return null;
    return preg_match('~^#/[a-z0-9/_-]{1,60}$~i', $rota) ? $rota : null;
}

function notifica(int $uid, string $tipo, string $texto, ?string $rota = null,
                  ?int $origem = null, ?string $ref = null): void
{
    /* Ninguém é avisado do que fez no próprio post. */
    if ($uid <= 0 || ($origem !== null && $uid === $origem)) return;

    try {
        db()->prepare(
            'INSERT IGNORE INTO notificacoes (usuario_id, tipo, texto, rota, origem_id, ref)
                  VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$uid, mb_substr($tipo, 0, 24), mb_substr(trim($texto), 0, 300),
                    notifica_rota($rota), $origem, $ref]);
    } catch (Throwable $e) { /* tabela nova: o aviso some, a ação segue */ }
}

/**
 * Quem foi marcado com @ no texto.
 *
 * Até dez por texto: marcação em massa é spam, e o teto corta isso sem
 * precisar moderar depois.
 */
function notifica_marcados(string $texto, int $de, string $quem, ?string $rota, string $ref): void
{
    if (!preg_match_all('/@([A-Za-z0-9_]{3,25})/', $texto, $m)) return;

    $logins = array_slice(array_unique(array_map('strtolower', $m[1])), 0, 10);
    if (!$logins) return;

    try {
        $vaz = implode(',', array_fill(0, count($logins), '?'));
        $st = db()->prepare("SELECT id FROM usuarios WHERE LOWER(login) IN ($vaz)");
        $st->execute(array_values($logins));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $alvo) {
            notifica((int) $alvo, 'marcacao', $quem . ' marcou você', $rota, $de, $ref . ':' . (int) $alvo);
        }
    } catch (Throwable $e) { /* idem */ }
}

/**
 * Avisa um grupo de uma vez.
 *
 * Uma linha por pessoa, escrita pelo próprio banco: com centenas de contas
 * isso é uma consulta só, e cada pessoa continua podendo marcar como lida a
 * dela sem mexer na dos outros.
 *
 * Grupos: todos, pro, gratis, testadores, selo:<slug>, sem_overlay,
 * inativos:<dias>, login:<login>.
 */
function notifica_grupo(string $grupo, string $texto, ?string $rota = null, ?string $ref = null): int
{
    $onde = '1';
    $args = [];

    /* Pro e grátis precisam apagar o que sobrou do próprio envio, e sem uma
       marca só dele o apagar pegaria avisos de outros envios. */
    $plano = $grupo === 'pro' || $grupo === 'gratis' || $grupo === 'nao_pro';
    if ($plano && $ref === null) $ref = 'env:' . bin2hex(random_bytes(6));

    if ($grupo === 'testadores') {
        $onde = 'u.beta = 1';
    } elseif (strpos($grupo, 'login:') === 0) {
        $onde = 'LOWER(u.login) = ?';
        $args[] = strtolower(substr($grupo, 6));
    } elseif (strpos($grupo, 'selo:') === 0) {
        $onde = 'EXISTS (SELECT 1 FROM usuario_selos us JOIN selos s ON s.id = us.selo_id
                          WHERE us.usuario_id = u.id AND s.slug = ?)';
        $args[] = substr($grupo, 5);
    } elseif ($grupo === 'sem_overlay') {
        $onde = 'NOT EXISTS (SELECT 1 FROM perfis p WHERE p.usuario_id = u.id)';
    } elseif (strpos($grupo, 'inativos:') === 0) {
        $onde = '(u.visto_em IS NULL OR u.visto_em < DATE_SUB(NOW(), INTERVAL ? DAY))';
        $args[] = max(1, (int) substr($grupo, 9));
    }

    try {
        $st = db()->prepare(
            "INSERT IGNORE INTO notificacoes (usuario_id, tipo, texto, rota, ref)
                  SELECT u.id, 'recado', ?, ?, ? FROM usuarios u WHERE $onde"
        );
        $st->execute(array_merge([mb_substr(trim($texto), 0, 300), notifica_rota($rota), $ref], $args));
        $n = $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }

    /* Pro e grátis dependem de assinatura, cortesia e testador juntos, que é
       conta de PHP e não de SQL: o fan-out pega todo mundo e estes dois
       filtram depois, apagando quem não era do grupo. */
    if ($plano) $n = notifica_so_plano($grupo, $ref);
    return $n;
}

/** Apaga do último envio quem não pertence ao grupo de plano pedido. */
function notifica_so_plano(string $grupo, ?string $ref): int
{
    require_once __DIR__ . '/assinatura.php';
    if ($ref === null) return 0;   /* sem a marca do envio, não apago nada */

    try {
        $st = db()->prepare('SELECT id, usuario_id FROM notificacoes WHERE ref <=> ? AND lida = 0');
        $st->execute([$ref]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return 0;
    }

    $fora = [];
    $ficam = 0;
    foreach ($linhas as $l) {
        $uid = (int) $l['usuario_id'];
        $a = acesso_do_usuario($uid);
        $ehPro = $a['ativo'] && $a['plano'] !== 'gratis';
        $quer = $grupo === 'pro' ? $ehPro : !$ehPro;
        if ($quer) { $ficam++; continue; }
        $fora[] = (int) $l['id'];
    }

    foreach (array_chunk($fora, 200) as $lote) {
        $vaz = implode(',', array_fill(0, count($lote), '?'));
        db()->prepare("DELETE FROM notificacoes WHERE id IN ($vaz)")->execute($lote);
    }
    return $ficam;
}
