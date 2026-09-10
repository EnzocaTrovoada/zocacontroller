/* ZocaController - textos editaveis pelo administrador.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   COMO ISTO FUNCIONA, E POR QUE ASSIM:

   Nao existe uma lista de chaves de texto espalhada pelo codigo. A chave e o
   RESUMO do proprio texto original — os doze primeiros digitos do md5 dele.
   Assim qualquer frase do site vira editavel sem precisar marcar nada no
   codigo, e nao ha o trabalho de batizar centenas de rotulos.

   O preco disso, e vale saber: se alguem MUDAR a frase no codigo, o resumo
   muda junto e a edicao para de valer — o texto volta ao novo padrao. Isso e
   proposital. Uma frase reescrita provavelmente mudou de sentido, e manter a
   edicao velha por cima dela seria pior do que perde-la.

   A coluna 'original' existe so pra tela de administracao poder mostrar o que
   estava escrito antes. Ninguem procura por ela. */

CREATE TABLE IF NOT EXISTS textos (
  chave         CHAR(12)  NOT NULL PRIMARY KEY,   /* md5 do original, cortado */
  original      TEXT      NOT NULL,
  valor         TEXT      NOT NULL,
  atualizado_em DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
