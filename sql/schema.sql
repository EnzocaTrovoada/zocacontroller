-- ZocaController — núcleo de contas, assinatura e overlays.
-- MySQL 5.7+ / MariaDB (Hostinger compartilhado).

CREATE TABLE usuarios (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  twitch_user_id VARCHAR(32)  NOT NULL,
  login          VARCHAR(64)  NOT NULL,
  email          VARCHAR(190) NULL,
  criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_twitch (twitch_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE planos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug           VARCHAR(32)  NOT NULL,
  nome           VARCHAR(64)  NOT NULL,
  preco_centavos INT UNSIGNED NOT NULL,
  periodo        ENUM('mensal','anual') NOT NULL DEFAULT 'mensal',
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Um perfil = um overlay configurado. chave_publica aparece em print e VOD:
-- ela SÓ LÊ. Alterar exige chave_secreta, que nunca sai do painel.
CREATE TABLE perfis (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  tipo          VARCHAR(32)  NOT NULL,       -- ponte | metas | relogio | chat
  nome          VARCHAR(64)  NOT NULL,
  config        JSON         NOT NULL,
  chave_publica VARCHAR(64)  NOT NULL,
  chave_secreta CHAR(64)     NOT NULL,       -- guardada como hash, nunca em claro
  criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_publica (chave_publica),
  KEY ix_usuario (usuario_id),
  CONSTRAINT fk_perfis_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- valido_ate e a fonte da verdade do acesso. So o webhook mexe nele.
CREATE TABLE assinaturas (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  plano_id    INT UNSIGNED NOT NULL,
  provedor    VARCHAR(32)  NOT NULL,          -- mercadopago | asaas | pix_automatico
  provedor_id VARCHAR(128) NOT NULL,          -- preapproval_id do lado deles
  status      ENUM('pendente','ativa','atrasada','cancelada') NOT NULL DEFAULT 'pendente',
  valido_ate  DATETIME     NULL,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_provedor (provedor, provedor_id),
  KEY ix_usuario (usuario_id),
  CONSTRAINT fk_assin_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Idempotencia: o mesmo aviso chega varias vezes. UNIQUE resolve sem gambiarra.
CREATE TABLE eventos_pagamento (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provedor     VARCHAR(32)  NOT NULL,
  evento_id    VARCHAR(128) NOT NULL,
  tipo         VARCHAR(64)  NOT NULL,
  payload      MEDIUMTEXT   NOT NULL,
  recebido_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processado_em DATETIME    NULL,
  erro         TEXT         NULL,
  UNIQUE KEY uq_evento (provedor, evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pareamento do celular / modo suporte: codigo curto vira chave longa.
CREATE TABLE dispositivos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  nome       VARCHAR(64)  NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso DATETIME     NULL,
  expira_em  DATETIME     NULL,                -- sessao de suporte tem prazo
  UNIQUE KEY uq_token (token_hash),
  KEY ix_usuario (usuario_id),
  CONSTRAINT fk_disp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE codigos_pareamento (
  codigo     CHAR(6)      PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  expira_em  DATETIME     NOT NULL,
  tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_cod_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limit (
  chave     VARCHAR(128) NOT NULL,
  janela    INT UNSIGNED NOT NULL,
  contagem  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (chave, janela)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO planos (slug, nome, preco_centavos, periodo) VALUES
  ('gratis', 'Gratis',      0,    'mensal'),
  ('pro',    'Pro mensal',  1990, 'mensal'),
  ('pro_ano','Pro anual',  17900, 'anual');
