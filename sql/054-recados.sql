/* ZocaController - recados de tempo em tempo.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O "timer" do StreamElements: uma mensagem que sai sozinha de tempos em
   tempos. Igual ao deles em tres coisas que importam:

     - varias mensagens que se revezam, pro chat nao ler sempre a mesma;
     - so sai se o chat andou (minimo de mensagens nos ultimos 5 minutos),
       senao o bot fica falando sozinho numa sala vazia;
     - da pra valer com a live no ar, fora do ar, ou nos dois.

   O RELOGIO E DAQUI, DO SERVIDOR. A ponte so pergunta "esta na hora?"; quem
   responde e o ultimo_em desta tabela. Assim duas pontes abertas (a fonte
   posta em duas cenas do OBS) nao mandam o mesmo recado duas vezes. */

CREATE TABLE IF NOT EXISTS recados (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  nome        VARCHAR(40)  NOT NULL DEFAULT '',
  mensagens   TEXT         NOT NULL,                  /* lista JSON, na ordem do rodizio */
  proxima     SMALLINT UNSIGNED NOT NULL DEFAULT 0,   /* qual sai da proxima vez */
  minutos     SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  linhas      SMALLINT UNSIGNED NOT NULL DEFAULT 3,   /* minimo de mensagens em 5 minutos */
  no_ar       TINYINT(1)   NOT NULL DEFAULT 1,
  fora_do_ar  TINYINT(1)   NOT NULL DEFAULT 0,
  ligado      TINYINT(1)   NOT NULL DEFAULT 1,
  ultimo_em   DATETIME     NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
