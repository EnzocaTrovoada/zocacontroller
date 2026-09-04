/* ZocaController - comandos que a pessoa inventa.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* Um comando novo nao e codigo novo: e um apelido pra uma acao que a ponte ja
   sabe fazer, com o argumento ja preenchido. "!brb" vira "cena Cenario BRB".

   A acao NAO e livre de proposito. Se desse pra inventar o que a ponte
   executa, o chat controlaria o OBS sem limite - entao ela so pode ser uma
   das quinze que existem no codigo, e o servidor recusa o resto.

   O UNIQUE por (usuario_id, nome) e o que impede dois comandos com o mesmo
   nome no mesmo canal, que seria um sorteio de qual roda. */
CREATE TABLE IF NOT EXISTS comandos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  nome       VARCHAR(20)  NOT NULL,
  acao       VARCHAR(20)  NOT NULL,
  argumento  VARCHAR(120) NULL,
  quem       VARCHAR(8)   NOT NULL DEFAULT 'mod',   /* chat | mod | dono */
  espera     SMALLINT UNSIGNED NOT NULL DEFAULT 5,  /* segundos */
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nome (usuario_id, nome),
  CONSTRAINT fk_comandos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
