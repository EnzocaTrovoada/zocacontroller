<?php
/**
 * Medidor: Dias desde que a conta foi criada.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'      => 'dias-de-conta',
    'nome'    => 'Dias desde que a conta foi criada',
    'unidade' => ['dia', 'dias'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT GREATEST(0, DATEDIFF(NOW(), criado_em)) FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
