/* ZocaController - uma chave por aparelho.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Entrar com a Twitch sorteava uma chave nova e apagava a anterior: entrar
   pelo celular derrubava o computador, e a ponte no OBS parava junto. Agora
   cada entrada ganha a sua chave, e as outras continuam valendo.

   A chave de usuarios.chave_painel continua sendo a principal: e a que os
   links antigos (a ponte, o painel do OBS) carregam. Redefinir a chave
   troca a principal e apaga todas as daqui. */

CREATE TABLE IF NOT EXISTS chaves_painel (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  chave_hash  CHAR(64)     NOT NULL,
  aparelho    VARCHAR(80)  NOT NULL DEFAULT '',
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  visto_em    DATETIME     NULL,
  UNIQUE KEY uq_chave (chave_hash),
  KEY ix_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* A chave principal nunca teve indice: cada chamada da ponte e do painel
   lia a tabela de usuarios inteira pra achar o dono.

   Se este comando der "Duplicate key name", o indice ja existe e o erro
   pode ser ignorado. */
ALTER TABLE usuarios ADD INDEX ix_chave_painel (chave_painel);
