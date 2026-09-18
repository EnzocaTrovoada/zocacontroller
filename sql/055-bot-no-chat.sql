/* ZocaController - o bot dentro do chat, sem depender do OBS.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Ate aqui quem lia o chat era a fonte do OBS: com o OBS fechado, nenhum
   comando respondia. Agora a Twitch avisa o nosso servidor a cada mensagem
   (EventSub channel.chat.message), e o bot responde de qualquer jeito.

   Uma linha por canal que ligou. O segredo e o que assina os avisos daquela
   assinatura - sem ele nao da pra provar que o aviso veio da Twitch. */

CREATE TABLE IF NOT EXISTS bot_chat (
  usuario_id INT UNSIGNED NOT NULL PRIMARY KEY,
  sub_id     VARCHAR(64)  NOT NULL,
  segredo    VARCHAR(80)  NOT NULL,
  ligado     TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  visto_em   DATETIME     NULL,
  KEY ix_sub (sub_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
