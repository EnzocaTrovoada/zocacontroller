<?php
/**
 * Medidor: Usos dos seus modelos por outras pessoas.
 *
 * Contrato em _MODELO.php.
 *
 * Usar o próprio modelo não soma: o corridas.php só conta uso de outra conta.
 */

return [
    'id'      => 'usos-de-modelo',
    'nome'    => 'Usos dos seus modelos por outras pessoas',
    'unidade' => ['uso', 'usos'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COALESCE(SUM(usos), 0) FROM corridas_modelo WHERE usuario_id = ? AND oculto = 0');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
