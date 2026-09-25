/* ZocaHub - quem usou o que, por dia.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   UMA LINHA POR CONTA, POR RECURSO, POR DIA. E SO.

   A pergunta que importa nao e "quantos cliques" - e "o que eu construo
   agora, e o que eu posso aposentar". Isso se responde com QUANTAS CONTAS
   DIFERENTES usam cada coisa. Um canal grande usando o TTS mil vezes por
   dia nao diz mais que mil canais usando uma vez.

   Por isso nao ha hora, nao ha IP, e nao ha o que a pessoa escreveu. O
   que cabe aqui nao reconstroi o que ninguem fez nem disse: so "esta
   conta usou o TTS nesse dia". A tabela fica minuscula e a pergunta
   continua respondida.

   A chave primaria sendo as tres colunas faz o INSERT IGNORE resolver a
   deduplicacao sozinho - sem SELECT antes, sem contador pra incrementar. */

CREATE TABLE IF NOT EXISTS uso (
  usuario_id INT UNSIGNED NOT NULL,
  recurso    VARCHAR(30)  NOT NULL,
  dia        DATE         NOT NULL,
  PRIMARY KEY (usuario_id, recurso, dia),
  KEY ix_dia (dia, recurso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
