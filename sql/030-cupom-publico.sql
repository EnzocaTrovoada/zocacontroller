/* ZocaController - cupom que aparece na vitrine de cupons.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   POR QUE NEM TODO CUPOM PODE APARECER:

   Cupom de parceiro e o ativo dele. Se o codigo do parceiro estiver numa
   lista publica dentro do site, ninguem precisa passar pelo link dele — o
   desconto continua valendo e a comissao deixa de acontecer. O parceiro
   trabalhou de graca.

   Por isso a lista publica e opt-in: o padrao e NAO aparecer, e so promocao
   nossa deve ser marcada. */

ALTER TABLE cupons
  ADD COLUMN publico TINYINT(1) NOT NULL DEFAULT 0;
