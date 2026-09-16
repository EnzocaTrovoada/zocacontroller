<?php
/**
 * Medidor: Backups do OBS guardados.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'backups',
    'nome'    => 'Backups do OBS guardados',
    'unidade' => ['backup', 'backups'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM backups WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
