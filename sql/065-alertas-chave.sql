/* ZocaHub - a chave geral dos alertas.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   DESLIGAR TUDO DE UMA VEZ E DIFERENTE DE DESMARCAR CADA UM. Quando algo
   da errado na live, ninguem vai abrir o editor do overlay e desmarcar
   follow, sub, bits e doacao um por um. Isto e uma chave so, no painel
   dentro do OBS, que cala todos ao mesmo tempo.

   A decisao mora no SERVIDOR, e nao no overlay: assim a fonte nao recebe
   nem o evento, e nao ha como ela decidir errado. */

ALTER TABLE usuarios
  ADD COLUMN alertas_ligados TINYINT(1) NOT NULL DEFAULT 1;
