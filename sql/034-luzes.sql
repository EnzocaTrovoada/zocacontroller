/* ZocaController - luzes controladas pelo chat.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   'driver' e o nome do arquivo em api/luzes/ que sabe falar com a marca.
   O banco nao conhece marca nenhuma: quem conhece e o arquivo. Marca nova
   e um arquivo novo, sem mexer aqui.

   'config' guarda a credencial da conta da pessoa (token, chave). Fica em
   texto, como o token da Twitch ja fica, e nunca volta pro navegador. */

CREATE TABLE IF NOT EXISTS luzes_contas (
  usuario_id    INT UNSIGNED NOT NULL,
  driver        VARCHAR(24)  NOT NULL,
  config        TEXT         NULL,
  aparelhos     TEXT         NULL,
  ligado        TINYINT(1)   NOT NULL DEFAULT 1,
  atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, driver)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Uma cena e uma palavra que o chat digita depois do comando: "!luz festa".
   'ms' maior que zero volta a luz ao que estava depois desse tempo — e o
   que "estava" e lido na hora, nao guardado, porque a pessoa pode ter
   mexido no aplicativo da marca no meio. */
CREATE TABLE IF NOT EXISTS luzes_cenas (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  palavra    VARCHAR(32)  NOT NULL,
  cor        VARCHAR(7)   NULL,
  brilho     TINYINT UNSIGNED NULL,
  ms         INT UNSIGNED NOT NULL DEFAULT 0,
  cargo      VARCHAR(8)   NOT NULL DEFAULT 'mod',
  UNIQUE KEY uq_palavra (usuario_id, palavra)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
