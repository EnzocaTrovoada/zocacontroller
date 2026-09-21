/* ZocaHub - quais comandos de fabrica ficam desligados.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Os de fabrica (!cena, !mute, !titulo, !panico...) moravam so na ponte e
   valiam sempre. Quem nao usa palpite tinha um !aposta ligado pra sempre,
   e quem odeia o !panico nao tinha como tirar.

   Guarda os DESLIGADOS, e nao os ligados: comando de fabrica novo entra
   funcionando pra todo mundo, sem ninguem precisar ir la ligar. Se fosse
   ao contrario, cada comando novo nasceria morto pra quem ja usa.

   Separados por espaco, numa coluna, e nao em tabela: sao poucos, mudam
   junto, e a ponte ja le a linha do usuario. */

ALTER TABLE usuarios
  ADD COLUMN embutidos_off VARCHAR(400) NOT NULL DEFAULT '';
