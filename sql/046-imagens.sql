/* ZocaController - imagens pequenas de cada usuario.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   Nasceu pro icone de cada trecho do speedrun, no formato que o LiveSplit
   consagrou, mas o deposito e generico de proposito: a proxima coisa que
   precisar de uma imagenzinha por usuario usa a mesma tabela.

   As regras sao as do som.php: nome sorteado por nos, tipo lido dos bytes,
   teto de tamanho e de quantidade, arquivo fora da pasta servida. */

CREATE TABLE IF NOT EXISTS imagens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  arquivo    VARCHAR(64)  NOT NULL,
  nome       VARCHAR(60)  NULL,
  bytes      INT UNSIGNED NOT NULL DEFAULT 0,
  tipo       VARCHAR(32)  NOT NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_dono (usuario_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
