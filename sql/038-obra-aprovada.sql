/* ZocaController - arte nova de artista ja aprovado passa por conferencia.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   O artista aprovado pode mandar mais artes depois. Cada uma entra como
   nao aprovada e so aparece na galeria quando alguem olhar — senao o selo
   de "sem IA" valeria so pra primeira leva. As que ja existem continuam
   aprovadas: foram conferidas junto com a inscricao. */

ALTER TABLE artista_obras ADD COLUMN aprovada TINYINT(1) NOT NULL DEFAULT 1;
