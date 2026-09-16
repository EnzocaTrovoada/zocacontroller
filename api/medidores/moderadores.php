<?php
/**
 * Medidor: Moderadores com link.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'moderadores',
    'nome'    => 'Moderadores com link',
    'unidade' => ['moderador', 'moderadores'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM convites_mod WHERE usuario_id = ? AND revogado = 0');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
