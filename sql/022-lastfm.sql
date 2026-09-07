/* ZocaController - a musica vindo do Last.fm.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   POR QUE EXISTE, ja que o Spotify ja lia a musica:

   O app do Spotify em modo de desenvolvimento atende no maximo CINCO contas,
   e cada uma precisa ser escrita a mao na lista do painel deles. Todo mundo
   fora da lista recebe 403 em qualquer chamada. Para um site com usuarios
   chegando sozinhos, isso e inviavel.

   O Last.fm nao tem lista de permissao nem OAuth: chave de API do servidor
   mais o nome de usuario, que e dado publico. Qualquer pessoa funciona no
   primeiro dia.

   O Spotify continua, porque so ele faz !pular, !fila e !like — o Last.fm e
   somente leitura. */

ALTER TABLE usuarios
  ADD COLUMN lastfm_user VARCHAR(64) NULL DEFAULT NULL;
