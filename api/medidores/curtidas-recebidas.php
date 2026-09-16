<?php
/**
 * Medidor: Curtidas recebidas.
 *
 * Contrato em _MODELO.php.
 *
 * Curtida no próprio post não conta: senão a conquista se ganhava sozinho.
 */

return [
    'id'      => 'curtidas-recebidas',
    'nome'    => 'Curtidas recebidas',
    'unidade' => ['curtida', 'curtidas'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM post_curtidas k JOIN posts p ON p.id = k.post_id
              WHERE p.usuario_id = ? AND p.escondido = 0 AND k.usuario_id <> p.usuario_id');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
