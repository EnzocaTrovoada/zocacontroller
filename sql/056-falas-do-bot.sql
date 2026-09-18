/* ZocaHub - as ultimas coisas que o bot falou.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Existe por um motivo so: ver o bot funcionando. "Esta ligado" e uma
   promessa; uma lista do que ele acabou de dizer e prova. Quando alguem
   diz que o comando nao respondeu, esta tabela responde se o problema foi
   o comando, a permissao ou a Twitch.

   NAO E HISTORICO. Guarda as ultimas falas e o resto e jogado fora na
   proxima vez que o painel abrir - registro de chat de terceiro que cresce
   pra sempre e dado pessoal acumulando sem motivo. */

CREATE TABLE IF NOT EXISTS bot_falas (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  texto      VARCHAR(520) NOT NULL,
  comando    VARCHAR(40)  NOT NULL DEFAULT '',
  quem       VARCHAR(40)  NOT NULL DEFAULT '',
  origem     VARCHAR(12)  NOT NULL DEFAULT '',
  erro       VARCHAR(160) NOT NULL DEFAULT '',
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_dono (usuario_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
