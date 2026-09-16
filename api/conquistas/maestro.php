<?php
/**
 * Conquista: Maestro.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'maestro',
    'nome'      => 'Maestro',
    'descricao' => 'Crie 5 comandos próprios pro chat.',
    'icone'     => '🎼',
    'grupo'     => 'live',
    'meta'      => 5,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM comandos WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => [
        ['tipo' => 'selo', 'slug' => 'maestro'],
        ['tipo' => 'overlays', 'quantos' => 1],
    ],
];
