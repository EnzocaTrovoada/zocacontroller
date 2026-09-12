/* ZocaController - modelos de speedrun, pra um usar o do outro.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   O modelo guarda so os NOMES dos trechos e os icones. Recorde e melhor
   parcial sao de quem correu: entrariam no modelo como tempo de outra
   pessoa, e a primeira corrida de quem aplicasse ja nasceria perdendo.

   Nao fica dentro da config da overlay porque aquele campo tem teto de
   4000 caracteres e e de uma overlay so. */

CREATE TABLE IF NOT EXISTS corridas_modelo (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  jogo       VARCHAR(60)  NOT NULL,
  categoria  VARCHAR(60)  NULL,
  trechos    TEXT         NOT NULL,
  usos       INT UNSIGNED NOT NULL DEFAULT 0,
  oculto     TINYINT(1)   NOT NULL DEFAULT 0,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_jogo (oculto, jogo),
  KEY ix_usados (oculto, usos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
