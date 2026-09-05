/* ZocaController - os sons que o proprio streamer manda.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O 'arquivo' e um nome SORTEADO por nos, nunca o nome que veio do
   computador da pessoa. Nome de arquivo vindo de fora e o caminho mais
   curto pra alguem gravar coisa onde nao devia.

   O 'nome' e so o rotulo que aparece na tela, e pode ser o que a pessoa
   quiser — ele nunca vira caminho de arquivo. */

CREATE TABLE IF NOT EXISTS sons (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id INT UNSIGNED NOT NULL,
  nome       VARCHAR(60)  NOT NULL,
  arquivo    VARCHAR(80)  NOT NULL,
  bytes      INT UNSIGNED NOT NULL DEFAULT 0,
  tipo       VARCHAR(20)  NOT NULL DEFAULT '',
  criado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sons_usuario (usuario_id),
  CONSTRAINT fk_sons_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
