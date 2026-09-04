/* ZocaController - o subathon aponta pra um overlay daqui.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* Antes: slug + token do site do relogio, copiados na mao pela pessoa.
   Agora: o id de um overlay dela aqui, escolhido numa lista.

   As colunas antigas ficam NULL-aveis e continuam funcionando: quem ja
   configurou nao perde o subathon no meio de uma live por causa disto. */
ALTER TABLE subathon
  ADD COLUMN perfil_id INT UNSIGNED NULL,
  ADD KEY ix_perfil (perfil_id);

ALTER TABLE subathon MODIFY slug  VARCHAR(64) NULL;
ALTER TABLE subathon MODIFY token VARCHAR(64) NULL;
