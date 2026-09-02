-- ZocaController — segunda leva: login da Twitch, relé de estado e fila de comandos.
-- Roda depois do schema.sql.

ALTER TABLE usuarios
  ADD COLUMN chave_painel  CHAR(64) NULL COMMENT 'hash da chave que a ponte e o painel usam',
  ADD COLUMN tw_refresh    TEXT     NULL COMMENT 'refresh token da Twitch; nunca sai daqui',
  ADD COLUMN tw_acesso     TEXT     NULL,
  ADD COLUMN tw_expira_em  DATETIME NULL,
  ADD COLUMN tw_escopos    TEXT     NULL;

-- O que a ponte publica. Uma linha por streamer, sempre sobrescrita:
-- é foto do agora, não histórico.
CREATE TABLE estado_ao_vivo (
  usuario_id    INT UNSIGNED NOT NULL PRIMARY KEY,
  estado        JSON     NOT NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_estado_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Comandos que os mods deixam e a ponte vem buscar.
CREATE TABLE fila_comandos (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  acao        VARCHAR(32)  NOT NULL,
  argumento   VARCHAR(255) NULL,
  quem        VARCHAR(64)  NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  entregue_em DATETIME     NULL,
  KEY ix_fila (usuario_id, entregue_em, id),
  CONSTRAINT fk_fila_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Convite de moderador: um link por pessoa, revogável, sem cadastro.
-- Um por pessoa de propósito — link compartilhado não dá pra tirar de um só.
CREATE TABLE convites_mod (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  nome       VARCHAR(64)  NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  pode_cena  TINYINT(1)   NOT NULL DEFAULT 1,
  pode_audio TINYINT(1)   NOT NULL DEFAULT 1,
  pode_canal TINYINT(1)   NOT NULL DEFAULT 0,  -- mudar título e categoria
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso DATETIME     NULL,
  revogado   TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_token (token_hash),
  KEY ix_usuario (usuario_id),
  CONSTRAINT fk_convite_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Palpites abertos, para saber o que resolver depois.
CREATE TABLE palpites (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  twitch_id   VARCHAR(64)  NOT NULL,
  titulo      VARCHAR(64)  NOT NULL,
  opcoes      JSON         NOT NULL,
  estado      VARCHAR(24)  NOT NULL DEFAULT 'ACTIVE',
  quem        VARCHAR(64)  NOT NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fechado_em  DATETIME     NULL,
  UNIQUE KEY uq_twitch (twitch_id),
  KEY ix_abertos (usuario_id, estado),
  CONSTRAINT fk_palpite_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
