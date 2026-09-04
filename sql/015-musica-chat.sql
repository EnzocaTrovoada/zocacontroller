/* ZocaController - o chat mexendo na musica.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* A playlist onde o chat pede musica. E criada uma vez, na primeira vez que
   alguem usa o comando, e o id fica guardado: sem isso cada pedido criaria
   uma playlist nova na conta da pessoa. */
ALTER TABLE spotify ADD COLUMN playlist_id VARCHAR(64) NULL;

/* De qual cargo pra cima o chat pode pedir musica.
   Fica no servidor e nao na URL da fonte do OBS: mudar de ideia nao pode
   obrigar a pessoa a refazer o endereco e colar de novo no OBS. */
ALTER TABLE usuarios ADD COLUMN musica_cargo VARCHAR(8) NOT NULL DEFAULT 'sub';

/* O que o chat ja pediu. Serve pra duas coisas: nao deixar a mesma pessoa
   entupir a fila, e voce poder ver depois quem pediu o que. */
CREATE TABLE IF NOT EXISTS musica_pedidos (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  quem       VARCHAR(64)  NOT NULL,
  faixa      VARCHAR(200) NOT NULL,
  uri        VARCHAR(120) NOT NULL,
  onde       VARCHAR(10)  NOT NULL,   /* fila | playlist */
  criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_recente (usuario_id, id),
  KEY ix_quem (usuario_id, quem, criado_em),
  CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
