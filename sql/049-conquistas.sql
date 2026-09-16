/* ZocaController - conquistas.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   CONQUISTA SO DA, NUNCA TIRA. Nada que ja esta liberado pra alguem passa
   a depender de conquista. O que ela entrega e extra: vaga de overlay a
   mais no plano gratis, selo de perfil, dias de Pro.

   As conquistas em si NAO moram no banco: cada uma e um arquivo em
   api/conquistas/. Aqui fica so quem ganhou o que, e quando.

   'premiado' separa ganhar de receber: se o premio e um selo que ainda nao
   foi desenhado, a conquista fica ganha e o selo chega quando existir. */

CREATE TABLE IF NOT EXISTS conquistas_usuario (
  usuario_id INT UNSIGNED NOT NULL,
  conquista  VARCHAR(40)  NOT NULL,
  ganhou_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  premiado   TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (usuario_id, conquista),
  KEY ix_premiado (premiado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Quando cada pessoa foi conferida pela ultima vez. Conferir custa umas
   dezenas de contagens, e a tela pergunta a cada visita: sem isto, cada
   carregamento do painel refaria todas. */
ALTER TABLE usuarios ADD COLUMN conquistas_em DATETIME NULL;

/* Quantas vagas de overlay as conquistas deram. Guardado, e nao somado na
   hora, porque quem le isto e o overlay dentro do OBS, a cada poucos
   segundos. A conferencia das conquistas mantem o numero em dia. */
ALTER TABLE usuarios ADD COLUMN vagas_conquista SMALLINT UNSIGNED NOT NULL DEFAULT 0;

/* Os selos que as conquistas dao. Sem desenho: o Enzo sobe a arte pelo
   painel de administracao, e ate la eles aparecem como etiqueta colorida. */
INSERT IGNORE INTO selos (slug, nome, cor, ordem, descricao) VALUES
  ('fundador',  'Fundador',  '#D9A441', 10, 'Montou a live inteira: overlays, ponte e comando próprio'),
  ('zocador',   'Zocador',   '#12A150', 11, 'Postou 25 vezes no feed'),
  ('querido',   'Querido',   '#E0457B', 12, 'Ganhou 50 curtidas no feed'),
  ('maestro',   'Maestro',   '#2F8FD8', 13, 'Criou 5 comandos próprios pro chat'),
  ('arquiteto', 'Arquiteto', '#8B5CF6', 14, 'Publicou um modelo de speedrun que outros usaram'),
  ('prevenido', 'Prevenido', '#0EA5A4', 15, 'Guardou o backup do OBS');
