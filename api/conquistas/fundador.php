<?php
/**
 * Conquista: Fundador.
 *
 * Contrato em _MODELO.php.
 *
 * Cada parte vale um ponto: as três juntas dão a meta. Os parênteses em
 * volta de cada comparação importam — sem eles o MySQL somaria antes de
 * comparar.
 */

return [
    'id'        => 'fundador',
    'nome'      => 'Fundador',
    'descricao' => 'Monte a live completa: 3 overlays, a ponte no OBS e um comando próprio.',
    'icone'     => '🏗️',
    'grupo'     => 'comeco',
    'meta'      => 3,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT ((SELECT COUNT(*) FROM perfis WHERE usuario_id = ?) >= 3)
                                  + ((SELECT COUNT(*) FROM estado_ao_vivo WHERE usuario_id = ?) > 0)
                                  + ((SELECT COUNT(*) FROM comandos WHERE usuario_id = ?) > 0)');
        $st->execute([$uid, $uid, $uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => [
        ['tipo' => 'selo', 'slug' => 'fundador'],
        ['tipo' => 'pro', 'dias' => 3],
    ],
];
