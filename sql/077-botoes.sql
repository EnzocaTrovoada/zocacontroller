/* ZocaHub - o botao fisico (Stream Deck e afins).

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   POR QUE UM TOKEN SEPARADO, E NAO A CHAVE DO PAINEL.

   O Stream Deck dispara um GET simples: nao da pra mandar cabecalho. Entao
   o segredo precisa viajar na URL - e URL fica no log do servidor, no
   historico e num print da tela de configuracao. A chave do painel controla
   o OBS inteiro e nao pode ir por ali de jeito nenhum.

   Este token faz UMA coisa: deixar um pedido na fila. Nao le nada, nao
   configura nada, nao lista nada. Vazou, o estrago e alguem trocar sua cena
   - e voce revoga num clique sem mexer no resto.

   GUARDADO COMO HASH, igual as outras chaves: o banco nunca ve o segredo. */

CREATE TABLE IF NOT EXISTS botoes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  nome        VARCHAR(40)  NOT NULL DEFAULT '',
  /* Sair do panico e a unica acao que nao vem junto por padrao: o panico
     existe pra proteger de quem nao deveria mandar, e um token em URL e
     justamente o que pode vazar. Quem quiser, liga sabendo. */
  pode_panico TINYINT(1)   NOT NULL DEFAULT 0,
  usos        INT UNSIGNED NOT NULL DEFAULT 0,
  usado_em    DATETIME     NULL,
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash),
  KEY ix_dono (usuario_id),
  CONSTRAINT fk_botao_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
