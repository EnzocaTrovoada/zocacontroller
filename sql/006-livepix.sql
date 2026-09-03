/* ZocaController - doacao pelo LivePix.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

CREATE TABLE livepix (
  usuario_id  INT UNSIGNED NOT NULL PRIMARY KEY,

  /* Credencial do LivePix do proprio streamer. Precisamos dela porque o aviso
     que eles mandam NAO diz o valor: so um id. O valor sai de uma consulta
     autenticada, e e essa consulta que impede alguem de forjar doacao. */
  client_id     VARCHAR(120) NOT NULL,
  client_secret VARCHAR(200) NOT NULL,

  /* O LivePix nao assina os avisos dele. Entao a unica prova de que o aviso
     veio de la e a URL secreta que so eles conhecem. */
  segredo_url CHAR(48) NOT NULL,

  ligado      TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_ok   DATETIME   NULL COMMENT 'quando chegou o ultimo aviso valido',
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_segredo (segredo_url),
  CONSTRAINT fk_lp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
