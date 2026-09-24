/* ZocaHub - o nome da cena de luz, separado da palavra do comando.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   A PALAVRA E O NOME NAO SAO A MESMA COISA.

   A palavra e o que o chat digita: curta, sem espaco, sem acento, porque
   ela vai depois do !luz. O nome e o que VOCE le na lista: "Festa junina",
   "Modo terror", "Luz de leitura".

   Estavam juntos, e isso forcava escolher: ou uma palavra boa de digitar e
   uma lista ilegivel, ou uma lista bonita e um comando que ninguem acerta. */

ALTER TABLE luzes_cenas
  ADD COLUMN nome VARCHAR(40) NOT NULL DEFAULT '';
