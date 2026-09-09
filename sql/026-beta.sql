/* ZocaController - quem chegou antes da cobranca nao perde nada.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   POR QUE UMA COLUNA E NAO UMA ASSINATURA DE CORTESIA:

   Dava pra inserir uma linha em 'assinaturas' com validade em 2099 pra cada
   testador. Mas ai o relatorio de cobranca passaria a misturar cortesia com
   dinheiro de verdade, e daqui a um ano ninguem lembraria quais foram quais.
   Uma coluna separada diz o que e: nao pagou, nao deve, e continua com tudo.

   O UPDATE sem WHERE e proposital e roda UMA VEZ: ele marca todo mundo que ja
   existe no momento em que a cobranca abre. Quem se cadastrar depois entra
   com beta = 0, que e o padrao da coluna.

   ATENCAO: rodar este arquivo duas vezes marca como beta tambem quem entrou
   no meio do caminho. Se precisar rodar de novo, rode SO o ALTER. */

ALTER TABLE usuarios
  ADD COLUMN beta TINYINT(1) NOT NULL DEFAULT 0;

UPDATE usuarios SET beta = 1;
