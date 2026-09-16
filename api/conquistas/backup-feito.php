<?php
/**
 * Conquista: Prevenido.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'backup-feito',
    'nome'      => 'Prevenido',
    'descricao' => 'Tenha um backup do OBS guardado aqui.',
    'icone'     => '🛟',
    'grupo'     => 'live',
    'meta'      => 1,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM backups WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'selo', 'slug' => 'prevenido'],
];
