/* ZocaController - a meta deixa de ser so da Twitch.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   A contagem passa a guardar de QUAL plataforma veio o numero. A coluna
   'fonte' continua a mesma e ganha um prefixo — 'seguidores' segue sendo a
   Twitch, e as outras viram 'youtube:inscritos', 'kick:seguidores'.

   Prefixo em vez de coluna nova de proposito: a chave primaria desta tabela e
   (usuario_id, fonte), e mexer em chave primaria de tabela com dado dentro,
   num banco de producao com gente usando, e risco que este caso nao precisa
   correr. O 16 do VARCHAR fica apertado com prefixo, entao ele cresce. */

ALTER TABLE contagens
  MODIFY COLUMN fonte VARCHAR(32) NOT NULL;

/* O canal do YouTube.

   Nao usa OAuth de proposito: a contagem de inscritos e dado PUBLICO, entao
   basta uma chave de API do servidor mais o id do canal. O caminho de OAuth
   exigiria verificacao do app pelo Google e tem teto de 100 usuarios enquanto
   nao for verificado — teto que nao se reseta.

   Guardamos o id resolvido pra nao gastar cota resolvendo o mesmo @handle a
   cada consulta. */
ALTER TABLE usuarios
  ADD COLUMN yt_canal  VARCHAR(48) NULL DEFAULT NULL,
  ADD COLUMN yt_handle VARCHAR(64) NULL DEFAULT NULL;
