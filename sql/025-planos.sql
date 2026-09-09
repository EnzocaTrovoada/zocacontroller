/* ZocaController - precos novos e o plano vitalicio.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O ENUM de periodo precisa crescer ANTES do INSERT: MySQL recusa valor fora
   do ENUM em vez de avisar, e a linha do vitalicio simplesmente nao entraria.

   'vitalicio' e periodo, nao gambiarra: quem paga uma vez tem validade que
   nao acaba. O codigo trata esse periodo dando uma data distante em vez de
   somar dias, e assim a mesma coluna valido_ate continua servindo pros tres
   planos — sem coluna nova e sem 'if vitalicio' espalhado por consulta. */

ALTER TABLE planos
  MODIFY COLUMN periodo ENUM('mensal','anual','vitalicio') NOT NULL DEFAULT 'mensal';

UPDATE planos SET preco_centavos = 1399  WHERE slug = 'pro';
UPDATE planos SET preco_centavos = 15000 WHERE slug = 'pro_ano';

/* ON DUPLICATE porque este arquivo pode ser rodado duas vezes sem quebrar
   nada — e em banco de producao rodar duas vezes acontece. */
INSERT INTO planos (slug, nome, preco_centavos, periodo)
     VALUES ('vitalicio', 'Vitalicio', 33000, 'vitalicio')
ON DUPLICATE KEY UPDATE
     nome = VALUES(nome),
     preco_centavos = VALUES(preco_centavos),
     periodo = VALUES(periodo);
