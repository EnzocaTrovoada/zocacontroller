/* ZocaController - subathon automatico.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

CREATE TABLE subathon (
  usuario_id INT UNSIGNED NOT NULL PRIMARY KEY,

  /* O overlay mora no relogio.zocahop.com. Guardamos aqui o link e o codigo
     de dono dele, porque e o servidor que soma tempo — assim o codigo nunca
     precisa viajar numa URL que aparece em print. */
  slug       VARCHAR(32)  NOT NULL,
  token      VARCHAR(64)  NOT NULL,

  ligado     TINYINT(1)   NOT NULL DEFAULT 1,

  /* Quantos SEGUNDOS cada coisa soma. Zero = nao soma. */
  seg_sub1   INT NOT NULL DEFAULT 300,    /* sub tier 1 e Prime */
  seg_sub2   INT NOT NULL DEFAULT 600,    /* tier 2 */
  seg_sub3   INT NOT NULL DEFAULT 1500,   /* tier 3 */
  seg_bits   INT NOT NULL DEFAULT 30,     /* por 100 bits */
  seg_follow INT NOT NULL DEFAULT 0,      /* precisa do EventSub */
  seg_real   INT NOT NULL DEFAULT 60,     /* por R$ 1 de doacao */

  /* Teto por evento: um cheer de 100 mil bits nao pode virar dois dias. */
  teto_evento INT NOT NULL DEFAULT 7200,

  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sub_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Historico do que somou. Serve para o streamer conferir depois ("por que
   subiu 40 minutos?") e para nao contar o mesmo evento duas vezes: o chat
   reentrega mensagem quando a conexao cai e volta. */
CREATE TABLE subathon_eventos (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  chave      VARCHAR(120) NOT NULL COMMENT 'id unico do evento na origem',
  tipo       VARCHAR(24)  NOT NULL,
  quem       VARCHAR(64)  NULL,
  detalhe    VARCHAR(64)  NULL,
  segundos   INT          NOT NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_evento (usuario_id, chave),
  KEY ix_recentes (usuario_id, id),
  CONSTRAINT fk_subev_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
