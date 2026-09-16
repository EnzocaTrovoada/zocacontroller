<?php
/**
 * Medidor: Overlays criadas.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'overlays',
    'nome'    => 'Overlays criadas',
    'unidade' => ['overlay', 'overlays'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM perfis WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
