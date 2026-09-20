/* ZocaHub - a coluna das tags, pra quem ja rodou o 057 sem ela.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   SE VOCE ACABOU DE RODAR O 057, PULE ESTE. A coluna ja veio junto la, e
   rodar aqui de novo so devolve "Duplicate column name" - que nao quebra
   nada, e so o banco dizendo que ja estava feito.

   Existe porque a tag certa depende do jogo: Minecraft pede uma coisa,
   Just Chatting pede outra. Guardadas com o par titulo+categoria, elas
   voltam junto quando a pessoa reusa o titulo - e escolher a categoria ja
   traz as tags que ela usou da ultima vez naquele jogo. */

ALTER TABLE canal_usados
  ADD COLUMN tags VARCHAR(300) NOT NULL DEFAULT '' AFTER categoria_id;
