/* ZocaController - curtidas, comentarios e foto propria.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   A curtida nao tem id proprio: a chave e o par post + pessoa, e e isso que
   impede a mesma pessoa curtir duas vezes sem precisar conferir antes. */

CREATE TABLE IF NOT EXISTS post_curtidas (
  post_id    INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_comentarios (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id    INT UNSIGNED NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  texto      VARCHAR(300) NOT NULL,
  escondido  TINYINT(1)   NOT NULL DEFAULT 0,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_post (post_id, escondido, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* A foto da Twitch ja vem sozinha no login. Esta e pra quem quer outra, e
   ganha da de la quando existe. */
ALTER TABLE usuarios ADD COLUMN foto_propria VARCHAR(64) NULL;
