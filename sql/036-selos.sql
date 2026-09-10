/* ZocaController - selos do feed.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   Eram duas colunas de sim ou nao no usuario. Viraram tabela porque agora
   sao varios, cada um com desenho proprio, e porque a mesma pessoa pode ter
   quantos forem: um selo por coluna daria um ALTER TABLE por selo novo.

   'arquivo' e o desenho que o Enzo subir. Enquanto nao tiver, o selo
   aparece como etiqueta escrita, na cor dele. */

CREATE TABLE IF NOT EXISTS selos (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug    VARCHAR(24)  NOT NULL,
  nome    VARCHAR(32)  NOT NULL,
  cor     VARCHAR(7)   NOT NULL DEFAULT '#12A150',
  arquivo VARCHAR(64)  NULL,
  ordem   INT          NOT NULL DEFAULT 0,
  ligado  TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuario_selos (
  usuario_id INT UNSIGNED NOT NULL,
  selo_id    INT UNSIGNED NOT NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, selo_id),
  KEY ix_selo (selo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO selos (slug, nome, cor, ordem) VALUES
  ('verificado', 'Verificado', '#1D9BF0', 1),
  ('pro',        'Pro',        '#8A6210', 2),
  ('artista',    'Artista',    '#6B3FA0', 3),
  ('tester',     'Testador',   '#12A150', 4);

/* Quem ja tinha os dois selos antigos continua com eles. */
INSERT IGNORE INTO usuario_selos (usuario_id, selo_id)
  SELECT u.id, s.id FROM usuarios u JOIN selos s ON s.slug = 'artista'
   WHERE u.selo_artista = 1;

INSERT IGNORE INTO usuario_selos (usuario_id, selo_id)
  SELECT u.id, s.id FROM usuarios u JOIN selos s ON s.slug = 'verificado'
   WHERE u.selo_streamer = 1;
