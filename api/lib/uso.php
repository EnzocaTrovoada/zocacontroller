<?php
/**
 * Quem usou o que, por dia.
 *
 * SÓ OS RELATÓRIOS MORAM AQUI. Quem marca é o uso_marca(), que fica no
 * db.php — todo arquivo já carrega aquele, então não há arquivo novo pra
 * esquecer de subir. Este aqui só o admin carrega: faltando ele, o que
 * quebra é uma tela de administração, e não o produto.
 *
 * PRA SOMAR UM RECURSO: uma linha em USO_RECURSOS e uma chamada a
 * uso_marca() onde ele acontece. O nome vira coluna de relatório pra
 * sempre, então ele é escolhido uma vez e não muda.
 */
require_once __DIR__ . '/db.php';

/**
 * Os recursos que a gente acompanha, e o nome de cada um na tela.
 *
 * A lista existe pra o relatório poder mostrar ZERO: sem ela, um recurso
 * que ninguém usa simplesmente não apareceria — e "ninguém usa isto" é a
 * informação mais valiosa aqui, justamente a que some sozinha.
 */
const USO_RECURSOS = [
    'overlay'    => 'Criar ou editar overlay',
    'tts'        => 'TTS (mensagem de voz)',
    'raid'       => 'Raid pelo painel',
    'comando'    => 'Comando do chat',
    'luzes'      => 'Luzes',
    'musica'     => 'Música / Spotify',
    'subathon'   => 'Subathon',
    'backup'     => 'Backup do OBS',
    'painel'     => 'Painel dentro do OBS',
    'mods'       => 'Painel dos moderadores',
    'contagem'   => 'Contagem regressiva',
    'oque'       => 'O que streamar',
    'cor'        => 'Cor do site',
    'bot'        => 'Bot no chat',
];

/**
 * Quantas contas diferentes usaram cada recurso, nos últimos N dias.
 *
 * Devolve TODOS os recursos, inclusive os de contagem zero.
 */
function uso_por_recurso(int $dias = 30): array
{
    $saida = [];
    foreach (USO_RECURSOS as $chave => $nome) {
        $saida[$chave] = ['nome' => $nome, 'contas' => 0];
    }

    try {
        $st = db()->prepare(
            'SELECT recurso, COUNT(DISTINCT usuario_id) AS contas
               FROM uso WHERE dia > DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY recurso'
        );
        $st->execute([max(1, min(365, $dias))]);
        foreach ($st->fetchAll() as $l) {
            if (isset($saida[$l['recurso']])) $saida[$l['recurso']]['contas'] = (int) $l['contas'];
        }
    } catch (Throwable $e) { /* sem tabela: tudo zero */ }

    return $saida;
}

/**
 * Quantas contas voltaram: usaram alguma coisa nesta semana E na passada.
 *
 * É o número que diz se o produto gruda. Um pico de gente nova com pouca
 * volta quer dizer que alguém divulgou, e não que o produto ficou melhor.
 */
function uso_voltaram(): array
{
    $zero = ['semana' => 0, 'passada' => 0, 'voltaram' => 0];
    try {
        $st = db()->query(
            "SELECT
               COUNT(DISTINCT CASE WHEN dia > DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                   THEN usuario_id END) AS semana,
               COUNT(DISTINCT CASE WHEN dia > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                                    AND dia <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                   THEN usuario_id END) AS passada
             FROM uso"
        );
        $l = $st->fetch() ?: [];

        /* Quem apareceu nas DUAS semanas. Fica numa consulta à parte: numa
           só, o CASE não sabe cruzar as duas condições por pessoa. */
        $v = db()->query(
            "SELECT COUNT(*) FROM (
               SELECT usuario_id FROM uso
                WHERE dia > DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                GROUP BY usuario_id
               HAVING MAX(dia > DATE_SUB(CURDATE(), INTERVAL 7 DAY)) = 1
                  AND MAX(dia <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) = 1
             ) x"
        );

        return ['semana' => (int) ($l['semana'] ?? 0),
                'passada' => (int) ($l['passada'] ?? 0),
                'voltaram' => (int) $v->fetchColumn()];
    } catch (Throwable $e) {
        return $zero;
    }
}

/** Quanto do que o Pro dá é realmente usado por quem paga. */
function uso_do_pro(int $dias = 30): array
{
    try {
        $st = db()->prepare(
            "SELECT u.recurso, COUNT(DISTINCT u.usuario_id) AS contas
               FROM uso u
               JOIN assinaturas a ON a.usuario_id = u.usuario_id
                                 AND a.status = 'ativa'
              WHERE u.dia > DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY u.recurso ORDER BY contas DESC"
        );
        $st->execute([max(1, min(365, $dias))]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        return [];
    }
}
