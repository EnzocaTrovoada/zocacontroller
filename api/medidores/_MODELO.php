<?php
/**
 * COMO ESCREVER UM MEDIDOR  —  leia só este arquivo.
 *
 * Um medidor responde uma pergunta só: "quanto disto esta pessoa já tem?".
 * As conquistas moram no banco e são editadas no painel de admin; o que
 * elas medem mora aqui, em código, de propósito — o painel escolhe um
 * medidor da lista, mas não escreve consulta nenhuma. Se desse pra
 * escrever SQL pelo painel, uma chave de admin vazada abriria o banco.
 *
 * Pra somar um medidor: copie este arquivo, troque o conteúdo, salve como
 * api/medidores/<id>.php e suba. Ele aparece sozinho na lista do editor.
 *
 * ---------------------------------------------------------------------
 * O QUE O ARQUIVO PRECISA DEVOLVER (return de um array)
 *
 *   id       igual ao nome do arquivo sem .php. Letras minúsculas, número
 *            e hífen. NUNCA mude o id de um medidor em uso: as conquistas
 *            que apontam pra ele parariam de contar.
 *   nome     o que aparece na lista do editor.
 *   unidade  [singular, plural], pro "3 de 25 posts" da tela.
 *   contar   function(int $uid): int
 *            Uma consulta só, com COUNT ou SUM. Roda pra todas as
 *            conquistas de uma vez, e cada medidor é contado uma vez por
 *            conferência mesmo que várias conquistas usem ele.
 *            Tabela que pode não existir ainda? Deixe estourar: o núcleo
 *            pega o erro e trata como zero.
 */

return [
    'id'      => 'modelo',
    'nome'    => 'Modelo',
    'unidade' => ['coisa', 'coisas'],

    'contar' => function (int $uid): int {
        return 0;
    },
];
