<?php
/**
 * Medidor: Marcas de luz conectadas.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'luzes',
    'nome'    => 'Marcas de luz conectadas',
    'unidade' => ['marca', 'marcas'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM luzes_contas WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
