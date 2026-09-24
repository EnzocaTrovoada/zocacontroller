/* ZocaHub - o nome do evento da Twitch nao cabia na coluna.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O CORTE ERA SILENCIOSO, E O SINTOMA NAO PARECIA COM A CAUSA.

   A coluna era VARCHAR(48) e o evento de resgate de pontos se chama
   channel.channel_points_custom_reward_redemption.add - 51 caracteres.
   Fora do modo estrito, o MySQL corta e nao reclama: a assinatura era
   criada na Twitch, funcionava, e ficava guardada aqui com o nome pela
   metade.

   Resultado: a tela dizia "falta ligar os avisos" pra uma assinatura que
   existia, e ligar de novo nao mudava nada - o proximo nome cortado
   colidia com o UNIQUE e a linha voltava igual.

   Cem da folga pros nomes que a Twitch ainda vai inventar. */

ALTER TABLE eventsub_assinaturas
  MODIFY COLUMN tipo VARCHAR(100) NOT NULL;
