/* ZocaHub - os titulos e categorias que ja foram usados.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Existe pra ninguem digitar do zero. Quem transmite a mesma serie tres
   vezes por semana reescreve o mesmo titulo tres vezes por semana; aqui
   ele vira um clique.

   Guarda o par TITULO + CATEGORIA junto, e nao duas listas: o titulo
   "Zerando o jogo - parte 4" so faz sentido com a categoria dele, e
   escolher um sem o outro e o erro que se comete ao vivo.

   NAO E HISTORICO DE TRANSMISSAO. Sao as ultimas combinacoes, cortadas na
   leitura - lista de titulo que cresce pra sempre nao serve pra escolher
   nada, so pra rolar. */

CREATE TABLE IF NOT EXISTS canal_usados (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  titulo        VARCHAR(160) NOT NULL,
  categoria     VARCHAR(120) NOT NULL DEFAULT '',
  categoria_id  VARCHAR(20)  NOT NULL DEFAULT '',
  /* As tags que estavam no ar com esse par. Separadas por espaco, que e o
     unico caractere que a Twitch NAO aceita dentro de uma tag - entao ele
     nunca vai aparecer no meio de uma. */
  tags          VARCHAR(300) NOT NULL DEFAULT '',
  usado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  /* O par e unico: usar de novo levanta a data em vez de criar linha nova,
     senao a lista vira o mesmo titulo dez vezes seguidas. */
  UNIQUE KEY ux_par (usuario_id, titulo, categoria_id),
  KEY ix_recentes (usuario_id, usado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
