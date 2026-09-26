/* ZocaHub - segurar a fala antes dela sair.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O TTS le o que o espectador escreveu, em voz alta, na live. Ate agora o
   unico controle era depois: a fala ja tinha comecado e restava o "pular".
   Com o modo segurar ligado, nada sai sem o streamer deixar. */

ALTER TABLE tts_config
  ADD COLUMN segurar TINYINT(1) NOT NULL DEFAULT 0;

/* Nasce aprovado: com o modo desligado - que e o padrao - a fila anda como
   sempre andou, e quem nunca ligar nada nem percebe que a coluna existe. */
ALTER TABLE tts_fila
  ADD COLUMN aprovado    TINYINT(1) NOT NULL DEFAULT 1,
  /* A JANELA DE 3 MINUTOS PASSA A CONTAR DA APROVACAO.

     Sem isto, moderar seria uma armadilha: a mensagem segurada por quatro
     minutos ja nasceria vencida, e o aprovar nao falaria nada - sem erro,
     sem aviso, sem pista. */
  ADD COLUMN aprovado_em DATETIME NULL,
  ADD KEY ix_espera (usuario_id, aprovado, falado_em);
