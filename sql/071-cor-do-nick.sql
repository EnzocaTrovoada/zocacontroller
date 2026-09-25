/* ZocaHub - a cor do nome de quem e Pro, no feed.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   GUARDA UMA COR, E NAO CSS - a mesma regra da cor do site. Hexadecimal
   de seis digitos, conferido letra por letra no servidor.

   Diferente da cor do site, esta TODO MUNDO ve: ela aparece no feed dos
   outros. Por isso o servidor tambem garante contraste, em vez de so
   avisar - aqui nao da pra deixar a pessoa escolher um nome ilegivel na
   tela dos outros. */

ALTER TABLE usuarios
  ADD COLUMN cor_nick CHAR(7) NOT NULL DEFAULT '';
