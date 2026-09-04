/* ZocaController - um comando, varias acoes.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* Antes cada comando era um apelido pra UMA acao. Na pratica o que a pessoa
   quer e "sair da mesa": mutar o microfone E esconder a camera E trocar de
   cena, com um comando so.

   As colunas acao e argumento ficam: sao o primeiro passo, e e o que os
   comandos que ja existem tem gravado. Quem tiver passos usa passos. */
ALTER TABLE comandos ADD COLUMN passos TEXT NULL;
