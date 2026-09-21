/* ZocaHub - anuncios de tempo em tempo, sozinhos.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   POR QUE ISTO EXISTE. Rodar anuncio no meio da live rende "tempo livre de
   pre-roll": pelo tanto que voce roda, a Twitch para de jogar anuncio na
   cara de quem acabou de chegar. Quem nunca roda e quem mais castiga o
   proprio espectador novo - ele abre o canal e leva propaganda antes de
   ver a sua cara.

   O RELOGIO E DO SERVIDOR, igual ao dos recados. A ponte so pergunta "esta
   na hora?"; quem responde e o ultimo_em, num UPDATE que so passa uma vez.
   Duas fontes do OBS abertas perguntam as duas, e so a primeira leva -
   anuncio em dobro seria dinheiro na mesa e espectador na porta. */

CREATE TABLE IF NOT EXISTS anuncios (
  usuario_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  ligado      TINYINT(1)   NOT NULL DEFAULT 0,
  /* De quanto em quanto tempo tentar. */
  minutos     SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  /* Quantos segundos de anuncio por vez (a Twitch aceita ate 180). */
  duracao     SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  /* Silencio no comeco: ninguem quer anuncio nos primeiros minutos, quando
     a live esta juntando gente. */
  espera_ini  SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  ultimo_em   DATETIME     NULL,
  /* O que a Twitch respondeu da ultima vez, pra tela poder mostrar por que
     nao rodou sem ninguem ter que adivinhar. */
  ultimo_erro VARCHAR(160) NOT NULL DEFAULT '',
  quantos     INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
