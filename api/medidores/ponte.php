<?php
/**
 * Medidor: Ponte ligada no OBS.
 *
 * Contrato em _MODELO.php.
 *
 * Vale 1 depois que a ponte publicou pela primeira vez, e 0 antes.
 */

return [
    'id'      => 'ponte',
    'nome'    => 'Ponte ligada no OBS',
    'unidade' => ['vez', 'vezes'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM estado_ao_vivo WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
