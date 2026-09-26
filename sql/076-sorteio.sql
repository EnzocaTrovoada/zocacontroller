/* ZocaHub - o sorteio entre quem esta no chat.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   GUARDADO PORQUE SORTEIO VALE PREMIO. Duas coisas dependem de existir
   registro: nao sortear a mesma pessoa duas vezes na mesma live, e poder
   provar depois quem ganhou quando alguem reclamar. Sorteio que so aparece
   no chat e some na rolagem nao prova nada. */

CREATE TABLE IF NOT EXISTS sorteios (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  ganhador    VARCHAR(40)  NOT NULL,
  /* Quantos estavam concorrendo. E o numero que diz se o sorteio foi entre
     tres pessoas ou entre duzentas - muda o que ele significa. */
  quantos     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  /* Quem mandou sortear, pra saber se foi o dono ou um mod. */
  quem        VARCHAR(40)  NOT NULL DEFAULT '',
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_recente (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
