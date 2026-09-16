/* ZocaController - o tamanho de cada selo na tela.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Dois desenhos do mesmo tamanho em pixels podem parecer de tamanhos
   diferentes: um preenche o quadro, o outro tem margem transparente, ou e
   redondo enquanto o outro e quadrado. O ajuste automatico corta a margem
   ao subir; estas duas colunas sao o acerto fino, feito a olho no painel.

   'escala' multiplica o tamanho (1.00 e o normal). 'ajuste_y' sobe ou
   desce o desenho em pixels, pra ele alinhar com o nome ao lado.
   Nenhum dos dois mexe no arquivo: vale ate pra GIF animado. */

ALTER TABLE selos ADD COLUMN escala DECIMAL(4,2) NOT NULL DEFAULT 1.00;
ALTER TABLE selos ADD COLUMN ajuste_y TINYINT NOT NULL DEFAULT 0;
