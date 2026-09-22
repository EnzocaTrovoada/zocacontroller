/* ZocaHub - a cor de acento que cada Pro escolhe pra si.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   GUARDA UMA COR, E NAO CSS. CSS livre de usuario parece generoso e e um
   buraco: seletor de atributo com background-image manda o que esta na
   tela pra fora sem script nenhum, e display:none no aviso de cobranca
   quebra o site de um jeito que chega como bug pro dono. Uma cor em
   hexadecimal nao faz nada disso.

   So o acento. Fundo e texto continuam do tema, porque e ali que a
   legibilidade morre - e o acento sozinho ja muda a cara do site inteiro,
   que e o que a pessoa quer. Os tons mais claro e mais escuro saem dele
   por conta, do lado do navegador, respeitando o tema em uso. */

ALTER TABLE usuarios
  ADD COLUMN cor_acento CHAR(7) NOT NULL DEFAULT '';
