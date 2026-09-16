<?php
/**
 * Conquista: Primeiro zoc.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'primeiro-zoc',
    'nome'      => 'Primeiro zoc',
    'descricao' => 'Poste alguma coisa no feed.',
    'icone'     => '✍️',
    'grupo'     => 'feed',
    'meta'      => 1,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM posts WHERE usuario_id = ? AND escondido = 0');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'overlays', 'quantos' => 1],
];
