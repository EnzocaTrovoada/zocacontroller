/* ZocaController - a musica que esta tocando.
   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte. */

/* O token do Spotify e de quem transmite, e da acesso a conta dele.
   Fica aqui e nunca chega no navegador: o overlay pergunta a MUSICA pro nosso
   servidor, e o servidor e quem fala com o Spotify. Assim a chave nao aparece
   em print, em VOD, nem no inspecionar elemento. */
CREATE TABLE IF NOT EXISTS spotify (
  usuario_id    INT UNSIGNED NOT NULL PRIMARY KEY,
  access_token  TEXT         NOT NULL,
  refresh_token TEXT         NOT NULL,
  expira_em     DATETIME     NOT NULL,
  criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_spotify_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Guarda o que estava tocando na ultima consulta.
   Existe pra nao perguntar pro Spotify a cada batida do OBS: a fonte pergunta
   de 15 em 15 segundos, e sao varios overlays por pessoa. */
CREATE TABLE IF NOT EXISTS spotify_cache (
  usuario_id    INT UNSIGNED NOT NULL PRIMARY KEY,
  json          TEXT         NULL,
  atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_spcache_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
