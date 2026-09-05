/* ZocaController - gatilhos: quando acontecer X, faça Y.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O comando customizado ja sabe fazer varias acoes em sequencia. A unica
   coisa que faltava era o disparo vir de um EVENTO em vez de alguem digitar.
   Por isso a forma aqui e a mesma da tabela comandos: uma lista de passos.

   O 'minimo' e por evento: 100 bits, 5 reais, 3 subs de presente. Sem ele,
   uma live movimentada trocaria de cena a cada 1 bit.

   A 'espera' e em segundos e vale pro gatilho inteiro: dez subs seguidos nao
   podem virar dez trocas de cena. */

CREATE TABLE IF NOT EXISTS gatilhos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id  INT UNSIGNED NOT NULL,
  evento      VARCHAR(16)  NOT NULL,
  minimo      INT UNSIGNED NOT NULL DEFAULT 1,
  espera      INT UNSIGNED NOT NULL DEFAULT 10,
  ligado      TINYINT(1)   NOT NULL DEFAULT 1,
  passos      TEXT         NULL,
  criado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gatilhos_usuario (usuario_id),
  CONSTRAINT fk_gatilhos_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
