/* ZocaController - correcoes da revisao.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* A ponte marcava os comandos como entregues DEPOIS de ler. Entre uma coisa e
   outra, uma segunda leitura pegava os mesmos comandos e executava tudo duas
   vezes — e isso acontece de verdade quando a ponte reconecta com a espera
   longa anterior ainda pendurada no servidor.
   Agora marca primeiro, com um lote, e le o que marcou. */
ALTER TABLE fila_comandos
  ADD COLUMN lote CHAR(24) NULL,
  ADD KEY ix_lote (lote);
