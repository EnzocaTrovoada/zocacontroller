/* ZocaController - avisos, seguir e suporte.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   'ref' e o que impede aviso repetido: descurtir e curtir de novo, ou a
   rotina diaria passando duas vezes no mesmo dia, caem na mesma chave e o
   INSERT IGNORE descarta. Aviso sem ref (ex.: recado escrito a mao) fica de
   fora da regra, porque em MySQL varios NULL convivem num indice unico. */

CREATE TABLE IF NOT EXISTS notificacoes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo       VARCHAR(24)  NOT NULL,
  texto      VARCHAR(300) NOT NULL,
  rota       VARCHAR(80)  NULL,
  origem_id  INT UNSIGNED NULL,
  ref        VARCHAR(60)  NULL,
  lida       TINYINT(1)   NOT NULL DEFAULT 0,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_caixa (usuario_id, lida, id),
  UNIQUE KEY uq_uma_vez (usuario_id, tipo, ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Seguir aqui dentro. A Twitch nao deixa ninguem seguir por terceiros desde
   2021, entao este seguir e do site: serve pro filtro do feed e pro aviso. */
CREATE TABLE IF NOT EXISTS feed_seguidores (
  seguidor_id INT UNSIGNED NOT NULL,
  seguido_id  INT UNSIGNED NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (seguidor_id, seguido_id),
  KEY ix_seguido (seguido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suporte (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT UNSIGNED NOT NULL,
  assunto      VARCHAR(80)  NOT NULL,
  mensagem     VARCHAR(2000) NOT NULL,
  arquivo      VARCHAR(64)  NULL,
  contato      VARCHAR(120) NULL,
  estado       ENUM('aberto','respondido','fechado') NOT NULL DEFAULT 'aberto',
  resposta     TEXT         NULL,
  criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  respondido_em DATETIME    NULL,
  KEY ix_fila (estado, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
