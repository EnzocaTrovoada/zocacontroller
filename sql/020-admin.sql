/* ZocaController - a parte de administracao.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   'admin' e ligado NA MAO, direto no banco. Nao existe tela pra promover
   ninguem a administrador, e isso e de proposito: a unica forma de virar
   admin e ter acesso ao banco. Um botao "tornar admin" numa tela e um botao
   que um dia alguem clica sem querer, ou que uma falha de sessao clica pela
   pessoa.

   'perfis_max' e 'recursos' sao SOBRESCRITAS por usuario. NULL quer dizer
   "vale o do plano" — assim o plano continua sendo a regra e a excecao fica
   visivel como excecao. */

ALTER TABLE usuarios
  ADD COLUMN admin      TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN perfis_max INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN recursos   TEXT         NULL DEFAULT NULL,
  ADD COLUMN visto_em   DATETIME     NULL DEFAULT NULL;
