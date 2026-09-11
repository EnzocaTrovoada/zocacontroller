/* ZocaController - o que cada selo quer dizer.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   E o texto que aparece quando alguem passa o mouse no selo. Os quatro de
   fabrica ja nascem com um; da pra trocar na tela de administracao. */

ALTER TABLE selos ADD COLUMN descricao VARCHAR(120) NULL;

UPDATE selos SET descricao = 'Conta conferida pelo ZocaController'
 WHERE slug = 'verificado' AND descricao IS NULL;
UPDATE selos SET descricao = 'Assinante do ZocaController Pro'
 WHERE slug = 'pro' AND descricao IS NULL;
UPDATE selos SET descricao = 'Artista conferido pelo ZocaController, sem IA'
 WHERE slug = 'artista' AND descricao IS NULL;
UPDATE selos SET descricao = 'Testou o ZocaController antes de todo mundo'
 WHERE slug = 'tester' AND descricao IS NULL;
