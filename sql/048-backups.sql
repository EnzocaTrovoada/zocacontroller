/* ZocaController - o backup do OBS de cada pessoa.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O conteudo NAO fica aqui: ele mora num arquivo fora da pasta servida,
   comprimido e cifrado. Uma coleccao de cenas passa fácil de um megabyte, e
   linha de um megabyte por pessoa e por versao entope o banco do plano
   compartilhado. Aqui fica so o cartao de identificacao de cada versao.

   'impressao' e o sha256 do conteudo cru. Serve pra nao guardar oito copias
   identicas de quem abre o OBS todo dia e nao mexe em nada.

   'origem' separa as duas portas: 'obs' e o retrato que a ponte tirou
   sozinha, 'arquivo' e o export que a pessoa subiu na mao. Sao coisas
   diferentes na hora de voltar, e a tela precisa dizer qual e qual. */

CREATE TABLE IF NOT EXISTS backups (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  origem     VARCHAR(8)   NOT NULL DEFAULT 'obs',
  arquivo    VARCHAR(80)  NOT NULL,
  nome       VARCHAR(120) NULL,
  bytes      INT UNSIGNED NOT NULL DEFAULT 0,
  cenas      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  fontes     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  impressao  CHAR(64)     NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_dono (usuario_id, id),
  KEY ix_impressao (usuario_id, impressao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
