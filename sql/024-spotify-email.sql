/* ZocaController - o e-mail de quem conectou o Spotify.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   PARA QUE SERVE, E SO PARA ISSO:

   O app esta em modo de desenvolvimento no Spotify, e nesse modo eles
   atendem CINCO contas, escritas a mao na lista do painel deles. Todo mundo
   fora da lista recebe 403. Sem guardar o e-mail, cadastrar alguem exige
   perguntar por fora, um a um.

   O e-mail nao vai pra lugar nenhum alem do painel de administracao, e sai
   junto com a conexao quando a pessoa desconecta o Spotify (a linha inteira
   e apagada). Nao e login, nao e identificacao, nao serve pra mais nada. */

ALTER TABLE spotify
  ADD COLUMN email VARCHAR(160) NULL DEFAULT NULL;
