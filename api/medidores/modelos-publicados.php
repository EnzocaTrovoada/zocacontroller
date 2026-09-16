<?php
/**
 * Medidor: Modelos de speedrun publicados.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'modelos-publicados',
    'nome'    => 'Modelos de speedrun publicados',
    'unidade' => ['modelo', 'modelos'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM corridas_modelo WHERE usuario_id = ? AND oculto = 0');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
