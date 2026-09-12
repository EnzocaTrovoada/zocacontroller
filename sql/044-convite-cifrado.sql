/* ZocaController - o link do moderador pode ser mostrado de novo.

   Comentario em bloco: se as quebras de linha se perderem no copiar e colar,
   um comentario de -- engoliria o comando seguinte.

   Quem autentica continua sendo o token_hash. Esta coluna guarda o mesmo
   token cifrado com a chave 'cifra' do config.php, que nao mora no banco:
   um backup vazado ou uma leitura indevida do banco nao entrega link que
   funcione. Convite antigo fica com NULL: esse so da pra gerar de novo. */

ALTER TABLE convites_mod ADD COLUMN token_cifrado VARCHAR(255) NULL;
