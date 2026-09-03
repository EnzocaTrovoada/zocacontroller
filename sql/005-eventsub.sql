/* ZocaController - EventSub da Twitch.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

ALTER TABLE usuarios
  ADD COLUMN es_segredo CHAR(64) NULL COMMENT 'segredo que assina os avisos da Twitch deste canal';

/* A Twitch entrega "pelo menos uma vez": a mesma mensagem chega repetida.
   Sem isto, um seguidor viraria dois. Guarda so o id e a hora; uma limpeza
   diaria apaga o que passou de uma hora. */
CREATE TABLE eventsub_recebidos (
  mensagem_id VARCHAR(80) NOT NULL PRIMARY KEY,
  criado_em   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_limpeza (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* O que este canal assinou na Twitch. Serve para nao assinar duas vezes: o
   limite e de 3 assinaturas iguais, e um cron que recria as cegas estoura
   isso e passa a contar cada seguidor tres vezes. */
CREATE TABLE eventsub_assinaturas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  tipo       VARCHAR(48)  NOT NULL,
  twitch_id  VARCHAR(64)  NOT NULL,
  estado     VARCHAR(40)  NOT NULL DEFAULT 'enabled',
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tipo (usuario_id, tipo),
  UNIQUE KEY uq_twitch (twitch_id),
  CONSTRAINT fk_es_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
