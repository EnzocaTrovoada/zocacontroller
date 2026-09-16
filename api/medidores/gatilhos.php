<?php
/**
 * Medidor: Gatilhos criados.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'gatilhos',
    'nome'    => 'Gatilhos criados',
    'unidade' => ['gatilho', 'gatilhos'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM gatilhos WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
