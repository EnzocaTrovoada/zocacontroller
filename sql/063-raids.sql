/* ZocaHub - os raids: a lista de quem o streamer acompanha.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   A LISTA GUARDA O LOGIN, E NAO O ID. O login e o que a pessoa digita e o
   que ela reconhece na tela; o id da Twitch nao diz nada pra ninguem. A
   pergunta "quem esta ao vivo" aceita ate 100 logins de uma vez, entao a
   lista inteira cabe numa chamada so. */

CREATE TABLE IF NOT EXISTS raid_lista (
  usuario_id INT UNSIGNED NOT NULL,
  login      VARCHAR(30)  NOT NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* QUANDO A LIVE COMECOU.

   O evento de raid nao diz ha quanto tempo a pessoa estava no ar, e a
   regra dos pontos exige duas horas. Perguntar isso na hora do raid seria
   uma corrida: muita gente encerra a live logo depois de raidar, e ai a
   Twitch ja responde "nao esta ao vivo". O stream.online marca a hora
   quando ela comeca, que e quando da pra saber com certeza. */
ALTER TABLE usuarios
  ADD COLUMN ao_vivo_desde DATETIME NULL;

/* QUEM NAO QUER APARECER NA LISTA PUBLICA.

   "Os do ZocaHub ao vivo agora" mostra o canal de uma pessoa pra todos os
   outros usuarios. Isso e bom pra quase todo mundo e precisa ter saida:
   ninguem entra numa vitrine sem poder sair dela. */
ALTER TABLE usuarios
  ADD COLUMN raid_oculto TINYINT(1) NOT NULL DEFAULT 0;

/* Cada raid numa linha: quem, pra quem, quantos foram, valeu ou nao, e
   POR QUE nao valeu. Quando alguem reclamar que o ponto nao contou, a
   resposta esta aqui - sem isso vira discussao sem fim no suporte. */
CREATE TABLE IF NOT EXISTS raid_feitos (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT UNSIGNED NOT NULL,
  alvo_id      VARCHAR(20)  NOT NULL,
  alvo_login   VARCHAR(30)  NOT NULL,
  espectadores INT UNSIGNED NOT NULL DEFAULT 0,
  pontos       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  motivo       VARCHAR(30)  NOT NULL DEFAULT '',
  criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_meus (usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* O saldo. Acumular e ilimitado; gastar e que tem teto. */
CREATE TABLE IF NOT EXISTS raid_saldo (
  usuario_id INT UNSIGNED NOT NULL PRIMARY KEY,
  pontos     INT UNSIGNED NOT NULL DEFAULT 0,
  dias       INT UNSIGNED NOT NULL DEFAULT 0,
  /* Quantos dias entraram neste mes, pro teto de 10. O mes fica junto:
     virou o mes, a conta recomeca sem precisar de cron. */
  dias_mes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  mes        CHAR(7) NOT NULL DEFAULT '',
  /* A chave do modo desconto. Desligada, o saldo so acumula - tem gente
     que prefere guardar pra quando parar de pagar. */
  desconto   TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Quantos dias de raid esta cobranca consumiu.

   Fica na linha da assinatura, e nao no saldo, porque o gasto so vale
   quando o pagamento e aprovado: dois checkouts abertos ao mesmo tempo
   gastariam o mesmo saldo duas vezes, e um checkout abandonado gastaria a
   toa. O webhook le daqui e desconta uma vez so. */
ALTER TABLE assinaturas
  ADD COLUMN dias_raid SMALLINT UNSIGNED NOT NULL DEFAULT 0;
