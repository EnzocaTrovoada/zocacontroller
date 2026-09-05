/* ZocaController - a conta do Kick.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Uma linha por streamer, como a do Spotify. O token do Kick da acesso a
   conta de quem transmite, entao ele NUNCA sai do servidor.

   O 'verificador' guarda o segredo do PKCE entre a ida e a volta do OAuth.
   Ele nasce na ida, e a volta o consome — por isso tem hora de nascimento:
   um verificador velho e um verificador que nao serve mais.

   O 'kick_id' e o dono do canal do lado do Kick. E por ele que o webhook
   descobre de quem e o evento que acabou de chegar: o Kick manda o id do
   canal, nao a nossa chave. */

CREATE TABLE IF NOT EXISTS kick (
  usuario_id    INT UNSIGNED NOT NULL,
  kick_id       VARCHAR(32)  NULL,
  canal         VARCHAR(64)  NULL,
  token         TEXT         NULL,
  refresh_token TEXT         NULL,
  expira_em     INT UNSIGNED NOT NULL DEFAULT 0,
  verificador   VARCHAR(128) NULL,
  verificador_em INT UNSIGNED NOT NULL DEFAULT 0,
  atualizado_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id),
  KEY idx_kick_id (kick_id),
  CONSTRAINT fk_kick_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* As inscricoes de evento, pra saber o que ja foi assinado e poder desfazer.
   Sem guardar, reconectar criaria inscricao duplicada e cada sub chegaria
   duas vezes — o mesmo cuidado que a tabela do EventSub da Twitch tem. */
CREATE TABLE IF NOT EXISTS kick_assinaturas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id   INT UNSIGNED NOT NULL,
  tipo         VARCHAR(48)  NOT NULL,
  kick_sub_id  VARCHAR(64)  NOT NULL,
  criado_em    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_kick_assin (usuario_id, tipo),
  CONSTRAINT fk_kick_assin_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Anti-repeticao do webhook.

   A propria doc do Kick chama o Kick-Event-Message-Id de "idempotent key", e
   nao documenta a politica de reentrega. Sem guardar o id, um POST valido
   capturado por alguem pode ser reenviado pra sempre — a assinatura continua
   matematicamente valida — e a reentrega legitima do proprio Kick viraria
   evento em dobro.

   O UNIQUE e a defesa: a segunda vez esbarra nele e para ali. */
CREATE TABLE IF NOT EXISTS kick_recebidos (
  message_id VARCHAR(40) NOT NULL,
  criado_em  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id),
  KEY idx_kick_recebidos_em (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
