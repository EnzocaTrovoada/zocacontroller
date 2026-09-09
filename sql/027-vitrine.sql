/* ZocaController - a vitrine da pagina inicial.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   Uma linha por assunto, guardada como JSON. Tabela generica de proposito: a
   vitrine vai ganhar coisa com o tempo (live do dia, hoje; talvez clipe da
   semana depois), e cada uma delas nao merece uma tabela propria.

   'atualizado_em' e o que decide se ja passou o dia. Sem cron nenhum: a
   primeira visita depois da virada e quem paga a conta de escolher a
   proxima, e ela custa uns segundos uma vez por dia. */

CREATE TABLE IF NOT EXISTS vitrine (
  chave         VARCHAR(32) NOT NULL PRIMARY KEY,
  valor         MEDIUMTEXT  NULL,
  atualizado_em DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Quem NAO pode aparecer na vitrine.

   Existe porque a live do dia e um estranho na pagina inicial do site: se
   alguem aparecer fazendo o que nao deve, tem que dar pra tirar na hora e pra
   sempre, sem esperar o dia virar. */
CREATE TABLE IF NOT EXISTS vitrine_bloqueio (
  login      VARCHAR(64) NOT NULL PRIMARY KEY,
  motivo     VARCHAR(160) NULL,
  criado_em  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
