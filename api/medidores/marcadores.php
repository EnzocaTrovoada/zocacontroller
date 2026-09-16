<?php
/**
 * Medidor: Momentos marcados na live.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'marcadores',
    'nome'    => 'Momentos marcados na live',
    'unidade' => ['momento', 'momentos'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM marcadores WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
