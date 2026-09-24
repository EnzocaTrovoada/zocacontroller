<?php
/**
 * O que falta no banco.
 *
 * NASCEU DE UM ERRO MEU: eu acrescentei linhas a arquivos SQL que já
 * tinham sido rodados, em vez de criar arquivos novos. Quem rodou o 062
 * antes ficou sem a coluna que eu somei depois — e o sintoma disso não é
 * um erro, é um recurso que simplesmente não faz nada.
 *
 * Aqui a resposta é direta: o que existe, o que falta, e qual arquivo
 * rodar. Sem adivinhação e sem abrir o phpMyAdmin pra conferir tabela por
 * tabela.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = exige_painel();

/* O que cada recurso precisa, e o arquivo que cria. Somar um recurso novo
   é somar uma linha aqui — e é o que mantém isto útil com o tempo. */
const BANCO_PRECISA = [
    ['tts',       'tts_config',           null,            '062-tts.sql',               'Falar no chat (TTS)'],
    ['tts',       'tts_config',           'calar_em',      '062-tts.sql',               'Pular a fala e desligar a voz'],
    ['tts',       'tts_fila',             null,            '062-tts.sql',               'A fila do que vai ser falado'],
    ['cor',       'usuarios',             'cor_acento',    '061-cor-do-usuario.sql',    'A cor do site'],
    ['raid',      'raid_lista',           null,            '063-raids.sql',             'A lista de quem você acompanha'],
    ['raid',      'usuarios',             'ao_vivo_desde', '063-raids.sql',             'Saber se a live durou 2 horas'],
    ['raid',      'usuarios',             'raid_oculto',   '063-raids.sql',             'Não aparecer na lista do ZocaHub'],
    ['raid',      'raid_feitos',          null,            '063-raids.sql',             'O histórico dos seus raids'],
    ['raid',      'raid_saldo',           null,            '063-raids.sql',             'Os pontos e os dias de Pro'],
    ['raid',      'assinaturas',          'dias_raid',     '063-raids.sql',             'Usar os dias como desconto'],
    ['painel',    'usuarios',             'painel_secoes', '064-painel-secoes.sql',     'Escolher o que aparece no OBS'],
    ['alertas',   'usuarios',             'alertas_ligados', '065-alertas-chave.sql',   'Desligar todos os alertas'],
    ['contagem',  'contagem_regressiva',  null,            '066-contagem-regressiva.sql', 'A contagem regressiva e a troca de cena'],
    ['luzes',     'luzes_cenas',          'nome',          '068-luz-cena-nome.sql',       'Dar nome às suas cenas de luz'],
];

/* O NOME DO EVENTO CABE NA COLUNA?

   Isto não é tabela nem coluna faltando: é uma coluna CURTA demais, que
   cortava o nome do evento de resgate de pontos e fazia o TTS parecer
   desligado mesmo ligado. Conferir tamanho é diferente de conferir
   existência, então vai numa função própria. */
function banco_tipo_cabe(): bool
{
    try {
        $st = db()->query("SHOW COLUMNS FROM eventsub_assinaturas LIKE 'tipo'");
        $c = $st->fetch();
        if (!$c) return true;
        preg_match('/\((\d+)\)/', (string) $c['Type'], $m);
        return !isset($m[1]) || (int) $m[1] >= 60;
    } catch (Throwable $e) {
        return true;                 /* sem a tabela, não é este o problema */
    }
}

/** A tabela existe? */
function banco_tem_tabela(string $t): bool
{
    try {
        db()->query('SELECT 1 FROM `' . $t . '` LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** A coluna existe? */
function banco_tem_coluna(string $t, string $c): bool
{
    try {
        db()->query('SELECT `' . $c . '` FROM `' . $t . '` LIMIT 0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

$faltam = [];
$ok = 0;

foreach (BANCO_PRECISA as [$grupo, $tabela, $coluna, $arquivo, $oQue]) {
    $tem = $coluna === null ? banco_tem_tabela($tabela) : banco_tem_coluna($tabela, $coluna);
    if ($tem) { $ok++; continue; }

    $faltam[] = [
        'oque'    => $oQue,
        'arquivo' => $arquivo,
        'onde'    => $coluna === null ? ('tabela ' . $tabela) : ('coluna ' . $tabela . '.' . $coluna),
    ];
}

/* Os arquivos a rodar, sem repetir: um arquivo pode explicar várias
   faltas, e listar ele três vezes faria parecer mais trabalho do que é. */
if (!banco_tipo_cabe()) {
    $faltam[] = [
        'oque'    => 'O TTS saber que os avisos estão ligados',
        'arquivo' => '067-eventsub-tipo-maior.sql',
        'onde'    => 'a coluna eventsub_assinaturas.tipo é curta e corta o nome do evento',
    ];
}

$arquivos = array_values(array_unique(array_column($faltam, 'arquivo')));
sort($arquivos);

json_saida([
    'tudo_certo' => !$faltam,
    'conferidos' => count(BANCO_PRECISA),
    'certos'     => $ok,
    'faltam'     => $faltam,
    'arquivos'   => $arquivos,
]);
