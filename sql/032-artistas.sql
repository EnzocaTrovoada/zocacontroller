/* ZocaController - artistas.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   'estado' comeca em pendente: ninguem aparece no site sem aprovacao.

   'sem_ia' e declaracao do artista, e 'verificado_em' e a conferencia do
   Enzo olhando o arquivo de processo. Nao existe deteccao automatica de
   arte por IA que preste — o selo e a palavra de quem verificou. */

CREATE TABLE IF NOT EXISTS artistas (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(80)  NOT NULL,
  arroba        VARCHAR(64)  NULL,
  link          VARCHAR(200) NULL,
  bio           VARCHAR(240) NULL,
  contato       VARCHAR(160) NULL,          /* so o admin ve */
  estado        ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
  sem_ia        TINYINT(1)   NOT NULL DEFAULT 0,
  verificado_em DATETIME     NULL,
  criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_estado (estado, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* 'processo' marca o arquivo que o artista mandou como prova de que fez a
   mao (rascunho, PSD, timelapse). Nao aparece no site: e so pra conferencia. */
CREATE TABLE IF NOT EXISTS artista_obras (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artista_id INT UNSIGNED NOT NULL,
  arquivo    VARCHAR(64)  NOT NULL,
  titulo     VARCHAR(120) NULL,
  processo   TINYINT(1)   NOT NULL DEFAULT 0,
  ordem      INT          NOT NULL DEFAULT 0,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_artista (artista_id, ordem),
  CONSTRAINT fk_obra_artista FOREIGN KEY (artista_id)
    REFERENCES artistas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
