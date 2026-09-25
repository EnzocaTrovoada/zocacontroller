<?php
/**
 * O que aparece no painel dentro do OBS, e em que ordem.
 *
 * GET            → a ordem atual e o catálogo de seções
 * POST {secoes}  → grava a ordem
 *
 * A CONFIGURAÇÃO MORA NO SERVIDOR, E NÃO NO NAVEGADOR. O painel é aberto
 * dentro do OBS, e muita gente tem mais de uma máquina — deixar isso no
 * localStorage faria a pessoa arrumar tudo de novo em cada computador.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/**
 * As seções que existem, na ordem em que o painel as mostra por padrão.
 *
 * PRA SOMAR UMA SEÇÃO: uma linha aqui e o mesmo apelido no data-secao do
 * docs/painel.html. O conferir.js compara os dois e reclama se divergirem.
 */
const PAINEL_SECOES = [
    'montar', 'contagem', 'overlays', 'raid', 'audio', 'fontes', 'estado',
    'ajuste', 'cena', 'canal', 'momentos', 'palpite', 'log',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        $st = db()->prepare('SELECT painel_secoes FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $cru = (string) $st->fetchColumn();
    } catch (Throwable $e) {
        $cru = '';
    }

    $lista = preg_split('/[\s,]+/', $cru, -1, PREG_SPLIT_NO_EMPTY);
    $lista = array_values(array_intersect($lista, PAINEL_SECOES));

    json_saida([
        /* Vazio é "nunca mexeu", e aí vale o padrão. Devolver a lista cheia
           aqui faria a próxima seção que eu criar nascer escondida pra
           quem já tinha salvo. */
        'secoes'   => $lista,
        'catalogo' => PAINEL_SECOES,
    ]);
}

$d = corpo_json();

/* Só apelidos do catálogo, sem repetido. O que a tela manda não é o que
   manda: quem manda é isto. */
$lista = array_values(array_unique(array_intersect(
    array_map('strval', (array) ($d['secoes'] ?? [])),
    PAINEL_SECOES
)));

try {
    db()->prepare('UPDATE usuarios SET painel_secoes = ? WHERE id = ?')
        ->execute([implode(' ', $lista), $uid]);
} catch (PDOException $e) {
    json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 064 no banco.')], 500);
}

json_saida(['ok' => true, 'secoes' => $lista]);
