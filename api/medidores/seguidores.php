<?php
/**
 * Medidor: Pessoas seguindo você.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'seguidores',
    'nome'    => 'Pessoas seguindo você',
    'unidade' => ['pessoa', 'pessoas'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM feed_seguidores WHERE seguido_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
