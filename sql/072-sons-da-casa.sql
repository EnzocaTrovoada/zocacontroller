/* ZocaHub - os sons que o Enzo sobe e todo mundo pode usar.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   UMA COLUNA, E NAO UMA TABELA NOVA.

   Som da casa e som de usuario sao a mesma coisa: um arquivo de audio com
   nome. O que muda e quem pode usar. Uma tabela separada obrigaria todo
   lugar que toca som a olhar em dois lugares e juntar - e a primeira vez
   que alguem esquecesse de olhar no segundo, o som da casa simplesmente
   nao tocaria, sem erro nenhum.

   O dono continua sendo quem subiu: se o admin sair, os sons dele saem
   junto pela chave estrangeira, e e isso mesmo que se quer. */

ALTER TABLE sons
  ADD COLUMN da_casa TINYINT(1) NOT NULL DEFAULT 0,
  ADD KEY ix_casa (da_casa);
