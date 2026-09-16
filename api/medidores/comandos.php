<?php
/**
 * Medidor: Comandos próprios.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'comandos',
    'nome'    => 'Comandos próprios',
    'unidade' => ['comando', 'comandos'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM comandos WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
