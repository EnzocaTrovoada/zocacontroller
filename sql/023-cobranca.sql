/* ZocaController - a cobranca de verdade.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   POR QUE PRECISA DE COLUNA NOVA:

   O checkout cria uma COBRANCA e o aviso volta minutos depois falando de um
   PAGAMENTO. Sao dois ids diferentes do lado do Mercado Pago, e a coluna
   provedor_id so guarda um. A 'referencia' e o que a gente inventa e manda
   junto no external_reference: ela volta na consulta e amarra as duas pontas.

   'teste' marca as linhas nascidas em modo de teste. Sem essa marca, depois
   de testar de ponta a ponta ninguem consegue distinguir o que foi ensaio do
   que foi dinheiro de verdade — e apagar no chute o historico de cobranca e
   exatamente o que nao se faz.

   'pago_centavos' guarda quanto entrou de fato. O preco do plano muda com o
   tempo; o que a pessoa pagou naquele dia, nao. */

ALTER TABLE assinaturas
  ADD COLUMN referencia    VARCHAR(64)  NULL DEFAULT NULL,
  ADD COLUMN pago_centavos INT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN teste         TINYINT(1)   NOT NULL DEFAULT 0,
  ADD KEY ix_referencia (referencia);
