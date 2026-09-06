/* ZocaController - meta de seguidores e de subs alimentando o subathon.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Mesma forma da meta de viewers que ja existia: alvo, quantos segundos vale
   bater, e de quanto o alvo sobe depois. O alvo mora no BANCO e nao na tela
   justamente pra disparar uma vez so por travessia — se ficasse na memoria do
   overlay, cada consulta somaria tempo de novo enquanto o numero estivesse
   acima do alvo, e uma noite boa viraria tempo infinito em minutos. */

ALTER TABLE subathon
  ADD COLUMN seguidores_alvo  INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN seguidores_seg   INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN seguidores_passo INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN subs_alvo        INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN subs_seg         INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN subs_passo       INT UNSIGNED NOT NULL DEFAULT 0;

/* Por que a contagem falhou da ultima vez.

   Hoje, quando a Twitch recusa (token velho, escopo que o usuario nunca deu),
   a meta simplesmente fica parada no numero antigo e NINGUEM descobre o
   motivo. Guardar o motivo e o que permite a tela dizer "falta permissao,
   reconecte sua conta" em vez de deixar a pessoa achando que o overlay
   quebrou. */
ALTER TABLE contagens
  ADD COLUMN erro VARCHAR(80) NOT NULL DEFAULT '';
