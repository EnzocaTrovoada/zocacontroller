/* ZocaController - conquistas que o admin edita pelo painel.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Antes cada conquista era um arquivo. Agora a conquista e um registro
   aqui, e so o que ela MEDE continua em codigo (api/medidores/): o painel
   escolhe um medidor da lista, mas nunca escreve consulta.

   As 14 que ja existiam entram com o mesmo id: quem ganhou continua com
   elas, e a tabela conquistas_usuario nao muda nada.

   Os premios sao colunas, e nao uma lista solta: sao tres tipos fixos, e
   coluna com tipo e mais facil de conferir do que texto livre.

   'ativa' = 0 e o "apagar" do painel: some pra quem nao tem, e quem tem
   continua com a conquista e com o premio. */

CREATE TABLE IF NOT EXISTS conquistas (
  id            VARCHAR(40)       NOT NULL PRIMARY KEY,
  nome          VARCHAR(60)       NOT NULL,
  descricao     VARCHAR(200)      NOT NULL DEFAULT '',
  icone         VARCHAR(16)       NOT NULL DEFAULT '🏆',
  grupo         VARCHAR(16)       NOT NULL DEFAULT 'comeco',
  ordem         SMALLINT          NOT NULL DEFAULT 50,
  medidor       VARCHAR(40)       NOT NULL,
  meta          INT UNSIGNED      NOT NULL DEFAULT 1,
  premio_vagas  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  premio_selo   VARCHAR(24)       NULL,
  premio_pro    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ativa         TINYINT(1)        NOT NULL DEFAULT 1,
  oculta        TINYINT(1)        NOT NULL DEFAULT 0,
  criado_em     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Quem mudou o que. Serve pra responder, meses depois, por que alguem
   tem um premio que a conquista hoje nao da mais. */
CREATE TABLE IF NOT EXISTS conquistas_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conquista  VARCHAR(40)  NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  antes      TEXT         NULL,
  depois     TEXT         NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_conquista (conquista, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO conquistas
  (id, nome, descricao, icone, grupo, ordem, medidor, meta, premio_vagas, premio_selo, premio_pro) VALUES
  ('primeiro-overlay', 'Primeira overlay', 'Crie a sua primeira overlay.',
     '🎬', 'comeco', 1, 'overlays', 1, 1, NULL, 0),
  ('ponte-ligada', 'Chat no comando', 'Coloque a ponte no OBS — é ela que deixa o chat mexer na sua live.',
     '🔌', 'comeco', 2, 'ponte', 1, 1, NULL, 0),
  ('primeiro-comando', 'Palavra mágica', 'Crie um comando próprio pro seu chat, tipo !brb.',
     '⌨️', 'comeco', 3, 'comandos', 1, 1, NULL, 0),
  ('fundador', 'Fundador', 'Monte a live completa: 3 overlays, a ponte no OBS e um comando próprio.',
     '🏗️', 'comeco', 4, 'live-completa', 3, 0, 'fundador', 3),
  ('backup-feito', 'Prevenido', 'Tenha um backup do OBS guardado aqui.',
     '🛟', 'live', 10, 'backups', 1, 0, 'prevenido', 0),
  ('luz-conectada', 'Faça-se a luz', 'Conecte as suas lâmpadas pra o chat mudar a cor com !luz.',
     '💡', 'live', 11, 'luzes', 1, 1, NULL, 0),
  ('maestro', 'Maestro', 'Crie 5 comandos próprios pro chat.',
     '🎼', 'live', 12, 'comandos', 5, 1, 'maestro', 0),
  ('momentos', 'Olho no lance', 'Marque 10 momentos da live com !marcar.',
     '📍', 'live', 13, 'marcadores', 10, 1, NULL, 0),
  ('primeiro-zoc', 'Primeiro zoc', 'Poste alguma coisa no feed.',
     '✍️', 'feed', 20, 'posts', 1, 1, NULL, 0),
  ('zocador', 'Zocador', 'Poste 25 vezes no feed.',
     '📣', 'feed', 21, 'posts', 25, 0, 'zocador', 0),
  ('querido', 'Querido', 'Receba 50 curtidas nos seus posts.',
     '💚', 'feed', 22, 'curtidas-recebidas', 50, 0, 'querido', 7),
  ('arquiteto', 'Arquiteto', 'Publique um modelo de speedrun e deixe outras pessoas usarem ele 3 vezes.',
     '🏛️', 'comunidade', 30, 'usos-de-modelo', 3, 0, 'arquiteto', 7),
  ('seguido', 'Tem torcida', 'Tenha 10 pessoas te seguindo aqui no site.',
     '⭐', 'comunidade', 31, 'seguidores', 10, 0, NULL, 7),
  ('conversador', 'Bom de papo', 'Comente 20 vezes nos posts de outras pessoas.',
     '💬', 'comunidade', 32, 'comentarios-em-outros', 20, 1, NULL, 0);
