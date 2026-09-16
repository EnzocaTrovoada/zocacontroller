<?php
/**
 * Medidor: Posts no feed.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'posts',
    'nome'    => 'Posts no feed',
    'unidade' => ['post', 'posts'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM posts WHERE usuario_id = ? AND escondido = 0');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
