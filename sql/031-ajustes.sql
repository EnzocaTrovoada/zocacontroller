/* ZocaController - ajustes livres do administrador.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   Tabela generica de chave e valor. Hoje guarda o CSS extra do site. */

CREATE TABLE IF NOT EXISTS ajustes (
  chave         VARCHAR(32) NOT NULL PRIMARY KEY,
  valor         MEDIUMTEXT  NULL,
  atualizado_em DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
