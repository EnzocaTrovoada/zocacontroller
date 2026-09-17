/* ZocaController - comandos que respondem no chat.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Um comando agora pode ter o passo "responder": um texto que sai no chat,
   com variaveis no jeito do StreamElements ($(sender), $(1:), $(count)...).
   E pra quem vem de la, a importacao traz os comandos prontos.

   O que o StreamElements tem e faltava aqui:
     apelidos       outros nomes pro mesmo comando (!links e !redes)
     espera_pessoa  a espera de cada pessoa, alem da espera geral
     ligado         desligar sem apagar
     origem         de onde veio ('streamelements' quando importado)

   O nome passa de 20 pra 30 letras: comando importado de la costuma ser
   comprido. */

ALTER TABLE comandos MODIFY nome VARCHAR(30) NOT NULL;

ALTER TABLE comandos
  ADD COLUMN apelidos      VARCHAR(255)      NULL,
  ADD COLUMN espera_pessoa SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN ligado        TINYINT(1)        NOT NULL DEFAULT 1,
  ADD COLUMN origem        VARCHAR(20)       NULL;

/* Os contadores do $(count). O de cada comando tem o nome do comando; os
   outros tem o nome que a pessoa escreveu ($(count mortes)). */
CREATE TABLE IF NOT EXISTS contadores (
  usuario_id    INT UNSIGNED NOT NULL,
  nome          VARCHAR(40)  NOT NULL,
  valor         INT          NOT NULL DEFAULT 0,
  atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
