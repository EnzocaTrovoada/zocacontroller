<?php
/**
 * As conquistas.
 *
 * GET               — confere (se já deu o tempo) e devolve as da pessoa
 * GET ?a=conferir   — só confere; o painel chama isto ao abrir
 * POST acao=admin   — o editor: todas, com quantas pessoas têm cada uma
 * POST acao=salvar  — criar ou editar (só admin)
 * POST acao=ativa   — ligar e desligar (só admin)
 * POST acao=apagar  — só se ninguém ganhou ainda (só admin)
 *
 * Conferir é o que entrega os prêmios. Ele acontece quando a pessoa abre o
 * painel ou a tela das conquistas: é quando ela está aqui pra ver, e é
 * quando o prêmio faz diferença.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();

/* SUBIDA PELA METADE NÃO PODE VIRAR "FAILED TO FETCH".

   Sem a biblioteca, o require quebra antes de qualquer resposta — e sem os
   cabeçalhos de acesso, o navegador só diz "Failed to fetch", que não
   aponta pra lugar nenhum. Aqui a resposta sai com os cabeçalhos e diz o
   arquivo que falta. */
if (!is_file(__DIR__ . '/lib/conquistas.php')) {
    json_saida(['erro' => 'O servidor ainda não tem as conquistas: falta subir api/lib/conquistas.php.'], 503);
}
require_once __DIR__ . '/lib/conquistas.php';

$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

header('Cache-Control: private, no-store');

const CONQ_FALTA_SQL = 'Falta rodar o SQL 051 no banco pra editar as conquistas.';

/* ------------------------------------------------------------------ *
 *  O editor (só admin)
 * ------------------------------------------------------------------ */

/** Quantas pessoas têm cada conquista. */
function conq_quantos(): array
{
    $n = [];
    try {
        $st = db()->query('SELECT conquista, COUNT(*) AS n FROM conquistas_usuario GROUP BY conquista');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $n[(string) $r['conquista']] = (int) $r['n'];
    } catch (Throwable $e) {}
    return $n;
}

function conq_admin_lista(): array
{
    $quantos = conq_quantos();
    $selos = [];
    try {
        $selos = db()->query('SELECT slug, nome FROM selos ORDER BY ordem, id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    return [
        'conquistas' => array_values(array_map(fn($c) => [
            'id'        => (string) $c['id'],
            'nome'      => (string) $c['nome'],
            'descricao' => (string) $c['descricao'],
            'icone'     => (string) $c['icone'],
            'grupo'     => (string) $c['grupo'],
            'ordem'     => (int) $c['ordem'],
            'medidor'   => (string) $c['medidor'],
            'meta'      => (int) $c['meta'],
            'vagas'     => (int) $c['premio_vagas'],
            'selo'      => (string) ($c['premio_selo'] ?? ''),
            'pro'       => (int) $c['premio_pro'],
            'ativa'     => (int) $c['ativa'],
            'oculta'    => (int) $c['oculta'],
            'quantos'   => $quantos[(string) $c['id']] ?? 0,
        ], conq_lista(true, true))),
        'medidores' => array_values(array_map(fn($m) => [
            'id' => (string) $m['id'], 'nome' => (string) ($m['nome'] ?? $m['id']),
        ], conq_medidores())),
        'selos'  => $selos,
        'grupos' => CONQ_GRUPOS,
    ];
}

/** Quem tem esta conquista volta a ser conferido na próxima visita. */
function conq_reconfere_quem_tem(string $id): void
{
    db()->prepare('UPDATE usuarios u JOIN conquistas_usuario cu ON cu.usuario_id = u.id
                      SET u.conquistas_em = NULL WHERE cu.conquista = ?')->execute([$id]);
}

/** Todo mundo volta a ser conferido na próxima visita. */
function conq_reconfere_todos(): void
{
    db()->exec('UPDATE usuarios SET conquistas_em = NULL');
}

function conq_registra(string $id, int $quem, ?array $antes, ?array $depois): void
{
    try {
        db()->prepare('INSERT INTO conquistas_log (conquista, usuario_id, antes, depois) VALUES (?, ?, ?, ?)')
            ->execute([$id, $quem,
                       $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                       $depois === null ? null : json_encode($depois, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { /* o registro não pode impedir a edição */ }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
    $ad->execute([$uid]);
    if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);
    trava('conquistas-admin', 120, 3600);

    $d    = corpo_json();
    $acao = (string) ($d['acao'] ?? '');

    try {
        if ($acao === 'admin') json_saida(conq_admin_lista());

        if ($acao === 'salvar') {
            $id = strtolower(trim((string) ($d['id'] ?? '')));
            if (!preg_match('/^[a-z0-9-]{2,40}$/', $id)) {
                json_saida(['erro' => 'O id precisa ter de 2 a 40 letras minúsculas, números ou hífen.'], 400);
            }

            $todas = conq_lista(true, true);
            $antes = $todas[$id] ?? null;
            $editando = !empty($d['editando']);
            if ($editando && !$antes) json_saida(['erro' => 'Essa conquista não existe mais.'], 404);
            if (!$editando && $antes) json_saida(['erro' => 'Já existe uma conquista com esse id.'], 409);

            $nome = trim((string) ($d['nome'] ?? ''));
            if (mb_strlen($nome) < 2) json_saida(['erro' => 'Dê um nome à conquista.'], 400);
            $nome = mb_substr($nome, 0, 60);
            $descricao = mb_substr(trim((string) ($d['descricao'] ?? '')), 0, 200);
            $icone = mb_substr(trim((string) ($d['icone'] ?? '')), 0, 16) ?: '🏆';

            $grupo = (string) ($d['grupo'] ?? 'comeco');
            if (!in_array($grupo, CONQ_GRUPOS, true)) $grupo = 'comeco';
            $ordem = max(-999, min(999, (int) ($d['ordem'] ?? 50)));

            $medidor = (string) ($d['medidor'] ?? '');
            if (!isset(conq_medidores()[$medidor])) json_saida(['erro' => 'Escolha o que a conquista mede.'], 400);
            $meta = max(1, min(1000000, (int) ($d['meta'] ?? 1)));

            $vagas = max(0, min(50, (int) ($d['vagas'] ?? 0)));
            $pro = max(0, min(365, (int) ($d['pro'] ?? 0)));
            $selo = strtolower(trim((string) ($d['selo'] ?? '')));
            if ($selo !== '') {
                $st = db()->prepare('SELECT 1 FROM selos WHERE slug = ?');
                $st->execute([$selo]);
                if (!$st->fetchColumn()) json_saida(['erro' => 'Esse selo não existe.'], 400);
            }

            $ativa = empty($d['ativa']) ? 0 : 1;
            $oculta = empty($d['oculta']) ? 0 : 1;

            /* TROCAR O QUE SE MEDE DEPOIS DE ALGUÉM GANHAR MUDA A REGRA DE
               QUEM JÁ TEM. A conquista vira outra coisa com o mesmo nome — e
               a lista de quem ganhou deixa de dizer a verdade. */
            $quantos = conq_quantos()[$id] ?? 0;
            if ($antes && (string) $antes['medidor'] !== $medidor && $quantos > 0) {
                json_saida(['erro' => $quantos . ' pessoas já ganharam esta conquista medindo outra coisa. '
                                    . 'Crie uma conquista nova em vez de trocar o que esta mede.'], 409);
            }

            if ($antes) {
                db()->prepare(
                    'UPDATE conquistas SET nome = ?, descricao = ?, icone = ?, grupo = ?, ordem = ?, medidor = ?,
                            meta = ?, premio_vagas = ?, premio_selo = ?, premio_pro = ?, ativa = ?, oculta = ?
                      WHERE id = ?'
                )->execute([$nome, $descricao, $icone, $grupo, $ordem, $medidor, $meta,
                            $vagas, $selo ?: null, $pro, $ativa, $oculta, $id]);
            } else {
                db()->prepare(
                    'INSERT INTO conquistas (id, nome, descricao, icone, grupo, ordem, medidor, meta,
                                             premio_vagas, premio_selo, premio_pro, ativa, oculta)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$id, $nome, $descricao, $icone, $grupo, $ordem, $medidor, $meta,
                            $vagas, $selo ?: null, $pro, $ativa, $oculta]);
            }

            $depois = conq_lista(true, true)[$id] ?? null;
            conq_registra($id, $uid, $antes, $depois);

            /* O QUE A MUDANÇA FAZ COM QUEM JÁ TEM, E COM QUEM AINDA NÃO TEM.

               Selo novo: chega na hora em quem já ganhou.
               Vagas: quem já ganhou é conferido de novo na próxima visita.
               Meta menor, conquista nova ou religada: todo mundo é conferido
               de novo — mais gente pode ter ganhado.
               Dias de Pro: só pra quem ganhar daqui pra frente. Dar de novo
               pra quem já tem seria Pro em dobro. */
            if ($selo !== '' && (!$antes || (string) ($antes['premio_selo'] ?? '') !== $selo)) {
                db()->prepare(
                    'INSERT IGNORE INTO usuario_selos (usuario_id, selo_id)
                     SELECT cu.usuario_id, s.id FROM conquistas_usuario cu JOIN selos s ON s.slug = ?
                      WHERE cu.conquista = ?'
                )->execute([$selo, $id]);
            }
            if ($antes && (int) $antes['premio_vagas'] !== $vagas) conq_reconfere_quem_tem($id);
            if (!$antes || $meta < (int) $antes['meta'] || ($ativa && !(int) $antes['ativa'])
                || (string) $antes['medidor'] !== $medidor) {
                conq_reconfere_todos();
            }

            json_saida(['ok' => true] + conq_admin_lista());
        }

        if ($acao === 'ativa') {
            $id = (string) ($d['id'] ?? '');
            $antes = conq_lista(true, true)[$id] ?? null;
            if (!$antes) json_saida(['erro' => 'Essa conquista não existe mais.'], 404);

            $ativa = empty($d['ativa']) ? 0 : 1;
            db()->prepare('UPDATE conquistas SET ativa = ? WHERE id = ?')->execute([$ativa, $id]);
            conq_registra($id, $uid, $antes, conq_lista(true, true)[$id] ?? null);
            if ($ativa) conq_reconfere_todos();

            json_saida(['ok' => true] + conq_admin_lista());
        }

        if ($acao === 'apagar') {
            $id = (string) ($d['id'] ?? '');
            $antes = conq_lista(true, true)[$id] ?? null;
            if (!$antes) json_saida(['erro' => 'Essa conquista não existe mais.'], 404);

            if ((conq_quantos()[$id] ?? 0) > 0) {
                json_saida(['erro' => 'Tem gente com esta conquista. Desative em vez de apagar: ela some '
                                    . 'pra quem não tem, e quem tem continua com ela e com o prêmio.'], 409);
            }
            db()->prepare('DELETE FROM conquistas WHERE id = ?')->execute([$id]);
            conq_registra($id, $uid, $antes, null);

            json_saida(['ok' => true] + conq_admin_lista());
        }
    } catch (PDOException $e) {
        json_saida(['erro' => CONQ_FALTA_SQL], 503);
    }

    json_saida(['erro' => 'Ação desconhecida.'], 400);
}

/* ------------------------------------------------------------------ *
 *  A pessoa
 * ------------------------------------------------------------------ */

if (($_GET['a'] ?? '') === 'conferir') {
    $novas = conq_confere($uid);
    /* O total vai junto pra medalha da barra: a tela compara com quantas a
       pessoa já tinha visto e mostra a diferença. */
    json_saida(['ok' => true, 'novas' => $novas, 'ganhas' => count(conq_ganhas($uid))]);
}

if (!conq_lista(true)) {
    json_saida(['erro' => 'O servidor ainda não tem as conquistas: falta rodar o SQL 051 no banco.'], 503);
}

/* Na tela, a conferência pode ser mais frequente: quem abriu a tela quer ver
   o que acabou de fazer virar conquista. */
$novas = conq_confere($uid, 30);
$lista = conq_estado($uid);

json_saida([
    'conquistas' => $lista,
    'novas'      => $novas,
    'ganhas'     => count(array_filter($lista, fn($c) => $c['ganhou'])),
    'total'      => count($lista),
    'vagas'      => conq_bonus_overlays($uid),
]);
