/* ZocaController - overlays proprios, com numero automatico.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* Quantos seguidores (ou subs) o canal tem.

   Existe pra nao perguntar isso pra Twitch a cada batida do overlay: a fonte
   no OBS pergunta de 15 em 15 segundos, e sao varios overlays por pessoa.
   Aqui o valor fica guardado e so e renovado quando passa da idade.

   Guardar tambem serve de rede: se a Twitch nao responder, o overlay mostra o
   ultimo numero conhecido em vez de despencar pra zero no meio da live. */
CREATE TABLE IF NOT EXISTS contagens (
  usuario_id    INT UNSIGNED NOT NULL,
  fonte         VARCHAR(16)  NOT NULL,      /* seguidores | subs */
  valor         INT UNSIGNED NOT NULL DEFAULT 0,
  atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, fonte),
  CONSTRAINT fk_contagens_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* A chave_secreta nasceu na fase do esqueleto e nunca foi usada: quem manda
   escrever e a chave do painel, no cabecalho. Deixar NOT NULL obrigaria a
   inventar um segredo pra jogar fora. */
ALTER TABLE perfis MODIFY chave_secreta CHAR(64) NULL DEFAULT NULL;
