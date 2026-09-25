/* ZocaHub - os erros que ninguem ve.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   O error_log do PHP existe, mas numa hospedagem compartilhada ele e
   quase inalcancavel - e o resultado pratico e que o primeiro a saber que
   algo quebrou e a pessoa que escreve reclamando.

   AGRUPADO, E NAO UMA LINHA POR OCORRENCIA. Um erro que acontece mil
   vezes e UM problema, nao mil; a chave unica junta as repeticoes e so
   conta. Assim a tabela nao cresce com o tamanho do estrago, e a tela
   mostra o que dói mais primeiro em vez das mil ultimas linhas iguais.

   A mensagem e cortada em 400 e NAO guarda o que o usuario digitou: o que
   interessa e o tipo do defeito e onde ele acontece. */

CREATE TABLE IF NOT EXISTS erros (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assinatura CHAR(32)    NOT NULL,
  tipo      VARCHAR(40)  NOT NULL,
  mensagem  VARCHAR(400) NOT NULL,
  onde      VARCHAR(160) NOT NULL DEFAULT '',
  quantos   INT UNSIGNED NOT NULL DEFAULT 1,
  contas    INT UNSIGNED NOT NULL DEFAULT 1,
  primeiro  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ass (assinatura),
  KEY ix_ultimo (ultimo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
