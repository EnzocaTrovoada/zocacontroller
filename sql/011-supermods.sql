/* ZocaController - supermods e argumento maior.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* SUPERMOD nao existe na Twitch: e uma camada a mais, escolhida pelo dono,
   pra separar "mod que eu confio o panico" de "mod que so cuida do chat".

   Fica aqui e nao na URL da fonte do OBS de proposito: na URL, mudar a lista
   obrigaria a pessoa a refazer o endereco e colar de novo no OBS. */
ALTER TABLE usuarios ADD COLUMN supermods VARCHAR(500) NULL;

/* O argumento as vezes e o nome de uma cena, as vezes e uma frase inteira. */
ALTER TABLE comandos MODIFY argumento VARCHAR(500) NULL;
