/* ZocaController - feed de posts e os selos de verificado.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   'nome_exibicao' e 'foto' vem da Twitch no login e ficam guardados aqui.
   Sem isso, desenhar um feed de trinta posts pediria trinta consultas de
   perfil a cada visita — e a Twitch limita, o feed nao.

   Os dois selos moram no usuario, e nao numa tabela de verificacoes: sao
   duas perguntas de sim ou nao respondidas por uma pessoa, e o lugar de
   ligar e desligar ja existe na tela de administracao. */

ALTER TABLE usuarios
  ADD COLUMN nome_exibicao VARCHAR(64)  NULL,
  ADD COLUMN foto          VARCHAR(200) NULL,
  ADD COLUMN selo_artista  TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN selo_streamer TINYINT(1)   NOT NULL DEFAULT 0;

/* A inscricao de artista feita por quem ja tem conta fica amarrada a ela:
   e assim que aprovar a inscricao acende o selo no feed. */
ALTER TABLE artistas ADD COLUMN usuario_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS posts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  texto      VARCHAR(500) NOT NULL,
  arquivo    VARCHAR(64)  NULL,
  escondido  TINYINT(1)   NOT NULL DEFAULT 0,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_feed  (escondido, criado_em),
  KEY ix_autor (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
