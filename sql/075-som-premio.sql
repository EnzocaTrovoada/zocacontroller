/* ZocaHub - o som que um premio de pontos toca.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   A biblioteca de sons ja existia e so tocava no alerta, um som pra tudo.
   Aqui cada premio do canal aponta pro seu: o chat resgata "buzina" e a
   buzina toca.

   A CHAVE E (usuario, premio): um premio toca um som. Permitir varios faria
   um resgate disparar tres audios ao mesmo tempo, que na live e barulho. */

CREATE TABLE IF NOT EXISTS sons_premio (
  usuario_id  INT UNSIGNED NOT NULL,
  premio_id   VARCHAR(64)  NOT NULL,
  som_id      INT UNSIGNED NOT NULL,
  /* Guardado na hora de amarrar, pra tela poder mostrar de que premio se
     trata sem perguntar a Twitch a cada abertura. */
  premio_nome VARCHAR(80)  NOT NULL DEFAULT '',
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, premio_id),
  KEY ix_som (som_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
