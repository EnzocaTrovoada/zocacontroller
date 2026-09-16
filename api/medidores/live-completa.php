<?php
/**
 * Medidor: Live montada (overlays, ponte e comando).
 *
 * Contrato em _MODELO.php.
 *
 * Vai de 0 a 3: três overlays, a ponte no OBS e um comando próprio valem um ponto cada.
 * Os parênteses em volta de cada comparação importam — sem eles o MySQL
 * somaria antes de comparar.
 */

return [
    'id'      => 'live-completa',
    'nome'    => 'Live montada (overlays, ponte e comando)',
    'unidade' => ['parte', 'partes'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT ((SELECT COUNT(*) FROM perfis WHERE usuario_id = ?) >= 3)
                                  + ((SELECT COUNT(*) FROM estado_ao_vivo WHERE usuario_id = ?) > 0)
                                  + ((SELECT COUNT(*) FROM comandos WHERE usuario_id = ?) > 0)');
        $st->execute([$uid, $uid, $uid]);
        return (int) $st->fetchColumn();
    },
];
