<?php
/**
 * Medidor: Pessoas que você segue.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'seguindo',
    'nome'    => 'Pessoas que você segue',
    'unidade' => ['pessoa', 'pessoas'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM feed_seguidores WHERE seguidor_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
