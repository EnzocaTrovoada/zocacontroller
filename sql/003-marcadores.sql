/* ZocaController - marcadores de momento.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

CREATE TABLE marcadores (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  quem       VARCHAR(64)  NOT NULL,
  nota       VARCHAR(200) NULL,
  tempo_live VARCHAR(16)  NULL COMMENT 'tempo decorrido da live, tipo 01:23:45',
  ao_vivo    TINYINT(1)   NOT NULL DEFAULT 0,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_usuario (usuario_id, criado_em),
  CONSTRAINT fk_marc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
