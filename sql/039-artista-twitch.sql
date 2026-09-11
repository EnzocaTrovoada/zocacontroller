/* ZocaController - a Twitch do artista, a parte da rede social.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   O link do artista e a rede social dele, pra onde o cartao leva. A Twitch
   vira um botao separado. Quem se inscreveu logado ja tem a Twitch pela
   conta; esta coluna e pra quem nao tem conta aqui, preenchida na
   moderacao. */

ALTER TABLE artistas ADD COLUMN twitch VARCHAR(25) NULL;
