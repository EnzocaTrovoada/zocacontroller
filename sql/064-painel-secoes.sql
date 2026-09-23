/* ZocaHub - quais secoes aparecem no painel dentro do OBS, e em que ordem.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   UMA LINHA DE TEXTO, E NAO UMA TABELA DE LINHAS. A ordem e o que aparece
   sao a mesma informacao: a lista das visiveis, na ordem em que ficam.
   Quem nao esta na lista nao aparece. Guardar isso como tabela pediria uma
   coluna de posicao e um UPDATE por secao pra cada arrastada.

   Vazio quer dizer "o padrao", e nao "nenhuma": assim quem nunca mexeu ve
   o painel inteiro, e uma secao nova que eu criar no futuro aparece pra
   essa pessoa sem ela precisar fazer nada. */

ALTER TABLE usuarios
  ADD COLUMN painel_secoes VARCHAR(400) NOT NULL DEFAULT '';
