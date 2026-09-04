/* ZocaController - feed de eventos e meta de viewers.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* O DIARIO DE EVENTOS.

   Separado do subathon_eventos de proposito: aquele e o livro-caixa do
   subathon, e so existe quando o subathon esta ligado e configurado. Este
   aqui e o que alimenta o feed na tela, e tem que funcionar mesmo pra quem
   nunca fez subathon nenhum.

   O UNIQUE na chave e o mesmo remedio de la: o chat reentrega mensagem
   quando a conexao cai, e a Twitch entrega "pelo menos uma vez". Sem ele o
   feed mostraria o mesmo sub duas vezes. */
CREATE TABLE IF NOT EXISTS eventos (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  chave      VARCHAR(120) NOT NULL,
  tipo       VARCHAR(16)  NOT NULL,   /* sub1 sub2 sub3 bits follow real */
  quem       VARCHAR(64)  NULL,
  quantidade INT UNSIGNED NOT NULL DEFAULT 1,
  detalhe    VARCHAR(64)  NULL,
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_evento (usuario_id, chave),
  KEY ix_recente (usuario_id, id),
  CONSTRAINT fk_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* META DE VIEWERS.

   Bateu o alvo, soma tempo no subathon e o alvo sobe sozinho. O alvo mora
   no banco e nao na memoria justamente pra so disparar UMA vez por travessia:
   sem isso, cada consulta do overlay somaria tempo de novo enquanto a live
   estivesse acima do numero. */
ALTER TABLE subathon
  ADD COLUMN viewers_alvo  INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN viewers_seg   INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN viewers_passo INT UNSIGNED NOT NULL DEFAULT 0;
