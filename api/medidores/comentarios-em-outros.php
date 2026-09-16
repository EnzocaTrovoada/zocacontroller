<?php
/**
 * Medidor: Comentários nos posts dos outros.
 *
 * Contrato em _MODELO.php.
 *
 * Só comentário no post dos outros: responder o próprio post não é conversa.
 */

return [
    'id'      => 'comentarios-em-outros',
    'nome'    => 'Comentários nos posts dos outros',
    'unidade' => ['comentário', 'comentários'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM post_comentarios c JOIN posts p ON p.id = c.post_id
              WHERE c.usuario_id = ? AND c.escondido = 0 AND p.usuario_id <> c.usuario_id');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
