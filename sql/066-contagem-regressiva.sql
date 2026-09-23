/* ZocaHub - a contagem regressiva, e a cena que ela chama no fim.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O ALVO E UM INSTANTE, E NAO "QUANTOS MINUTOS FALTAM".

   Guardar "faltam 15 minutos" obrigaria alguem a descontar o tempo que
   passa, e quem contaria? O overlay, que pode ser reaberto; a ponte, que
   pode cair. Guardando a HORA em que acaba, todo mundo que olha chega na
   mesma resposta sozinho - inclusive uma fonte que nasceu agora.

   A cena e trocada UMA VEZ. O trocada_em marca que ja foi: sem ele, a
   ponte trocaria de cena a cada leitura depois do prazo, e a pessoa nao
   conseguiria sair da cena de "ja vai comecar". */

CREATE TABLE IF NOT EXISTS contagem_regressiva (
  usuario_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  alvo        DATETIME     NULL,
  /* Cena do OBS pra ir quando zerar. Vazio = so mostra e para. */
  cena        VARCHAR(100) NOT NULL DEFAULT '',
  /* O que fica escrito depois do zero. */
  fim_texto   VARCHAR(80)  NOT NULL DEFAULT 'Já vai começar!',
  trocada_em  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
