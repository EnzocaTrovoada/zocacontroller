/* ZocaHub - o TTS: o que o espectador manda falar.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   DUAS TABELAS PORQUE SAO DOIS TEMPOS. A config muda quando o streamer
   mexe (raro); a fila enche quando o chat resgata (o tempo todo). Juntar
   as duas faria a config ser reescrita a cada mensagem.

   O TEXTO FICA AQUI COMO TEXTO. Ele foi digitado por um espectador, entao
   nunca vira endereco, nunca vira codigo, nunca vira HTML - nem aqui nem
   na tela. Quem le escreve com textContent e pronto. */

CREATE TABLE IF NOT EXISTS tts_config (
  usuario_id  INT UNSIGNED NOT NULL PRIMARY KEY,
  ligado      TINYINT(1)   NOT NULL DEFAULT 0,
  /* A voz de quem nao escolheu nenhuma. */
  voz         VARCHAR(20)  NOT NULL DEFAULT 'padrao',
  /* As vozes que participam do sorteio, separadas por espaco. So Pro usa
     mais de uma. */
  vozes       VARCHAR(300) NOT NULL DEFAULT '',
  aleatorio   TINYINT(1)   NOT NULL DEFAULT 0,
  /* Prefixo no chat ("grave: oi") pra quem resgatou escolher a voz. Pro. */
  prefixo     TINYINT(1)   NOT NULL DEFAULT 0,
  max_letras  SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  /* Palavras que nao sao faladas, separadas por espaco ou virgula. */
  bloqueadas  TEXT         NULL,
  /* O id do premio de pontos do canal que dispara. Vazio = nao dispara. */
  premio_id   VARCHAR(64)  NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* A fila. Curta de proposito: o que nao foi falado em pouco tempo perdeu a
   hora, e ler agora uma mensagem de vinte minutos atras e pior do que nao
   ler. A faxina acontece na leitura. */
CREATE TABLE IF NOT EXISTS tts_fila (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  texto       VARCHAR(400) NOT NULL,
  voz         VARCHAR(20)  NOT NULL DEFAULT 'padrao',
  quem        VARCHAR(40)  NOT NULL DEFAULT '',
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  falado_em   DATETIME     NULL,
  KEY ix_fila (usuario_id, falado_em, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
