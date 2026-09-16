<?php
/**
 * COMO ESCREVER UMA CONQUISTA  —  leia só este arquivo.
 *
 * Este é o contrato inteiro. Pra somar uma conquista nova não é preciso
 * conhecer mais nada do projeto: copie este arquivo, troque o conteúdo,
 * salve como api/conquistas/<id>.php e suba. O site acha sozinho, confere
 * quem já cumpriu e entrega o prêmio.
 *
 * ---------------------------------------------------------------------
 * A REGRA QUE NÃO SE QUEBRA
 *
 * Conquista só DÁ. Nunca tranque atrás de uma conquista algo que hoje está
 * liberado pra alguém. Quem já usa o site não pode acordar sem uma coisa
 * porque ainda não "ganhou" ela.
 *
 * ---------------------------------------------------------------------
 * O QUE O ARQUIVO PRECISA DEVOLVER (return de um array)
 *
 *   id         igual ao nome do arquivo sem .php. Letras minúsculas, número
 *              e hífen. NUNCA mude o id de uma conquista que já foi ganha:
 *              quem ganhou deixaria de ter ganhado.
 *   nome       como aparece na tela. Curto.
 *   descricao  o que fazer pra ganhar, dito pra quem não é técnico.
 *   icone      um emoji.
 *   grupo      'comeco' | 'feed' | 'live' | 'comunidade' — só pra ordenar
 *              a tela.
 *   meta       o número a alcançar (1 pra "fez uma vez").
 *   progresso  function(int $uid): int
 *              Devolve quanto a pessoa já tem. Pode passar da meta. Faça
 *              UMA consulta, com COUNT ou SUM: isto roda pra todas as
 *              conquistas de uma vez.
 *              Tabela que pode não existir ainda? Deixe estourar: o núcleo
 *              pega o erro e trata como zero.
 *   premio     o que a pessoa ganha. Um destes, ou uma lista deles:
 *                ['tipo' => 'overlays', 'quantos' => 2]
 *                    vagas de overlay a mais no plano grátis. Somam.
 *                ['tipo' => 'selo', 'slug' => 'fundador']
 *                    o selo precisa existir na tabela 'selos'. Se ainda não
 *                    existir, a conquista fica ganha e o selo chega sozinho
 *                    quando for criado.
 *                ['tipo' => 'pro', 'dias' => 7]
 *                    dias de Pro. Somam em cima do que a pessoa já tiver
 *                    de cortesia, nunca encurtam.
 *   oculta     opcional, true: só aparece na tela depois de ganha.
 *   ordem      opcional, número: desempata dentro do grupo (menor vem antes).
 *
 * ---------------------------------------------------------------------
 * O QUE JÁ EXISTE: veja os outros arquivos desta pasta. O jeito mais rápido
 * de escrever uma nova é copiar a mais parecida.
 */

return [
    'id'        => 'modelo',
    'nome'      => 'Modelo',
    'descricao' => 'Este arquivo é exemplo e não aparece na tela.',
    'icone'     => '🏆',
    'grupo'     => 'comeco',
    'meta'      => 1,
    'oculta'    => true,     /* o núcleo ignora arquivos começados por _ de qualquer jeito */

    'progresso' => function (int $uid): int {
        return 0;
    },

    'premio' => ['tipo' => 'overlays', 'quantos' => 1],
];
