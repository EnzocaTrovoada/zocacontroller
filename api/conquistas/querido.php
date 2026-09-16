<?php
/**
 * Conquista: Querido.
 *
 * Contrato em _MODELO.php.
 *
 * Curtida no próprio post não conta: senão a conquista se ganhava sozinho.
 */

return [
    'id'        => 'querido',
    'nome'      => 'Querido',
    'descricao' => 'Receba 50 curtidas nos seus posts.',
    'icone'     => '💚',
    'grupo'     => 'feed',
    'meta'      => 50,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM post_curtidas k JOIN posts p ON p.id = k.post_id
              WHERE p.usuario_id = ? AND p.escondido = 0 AND k.usuario_id <> p.usuario_id');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => [
        ['tipo' => 'selo', 'slug' => 'querido'],
        ['tipo' => 'pro', 'dias' => 7],
    ],
];
