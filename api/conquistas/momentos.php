<?php
/**
 * Conquista: Olho no lance.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'momentos',
    'nome'      => 'Olho no lance',
    'descricao' => 'Marque 10 momentos da live com !marcar.',
    'icone'     => '📍',
    'grupo'     => 'live',
    'meta'      => 10,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM marcadores WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'overlays', 'quantos' => 1],
];
