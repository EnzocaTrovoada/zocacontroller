<?php
/**
 * Conquista: Arquiteto.
 *
 * Contrato em _MODELO.php.
 *
 * Usar o próprio modelo não soma: o corridas.php só conta uso de outra conta.
 */

return [
    'id'        => 'arquiteto',
    'nome'      => 'Arquiteto',
    'descricao' => 'Publique um modelo de speedrun e deixe outras pessoas usarem ele 3 vezes.',
    'icone'     => '🏛️',
    'grupo'     => 'comunidade',
    'meta'      => 3,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COALESCE(SUM(usos), 0) FROM corridas_modelo WHERE usuario_id = ? AND oculto = 0');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => [
        ['tipo' => 'selo', 'slug' => 'arquiteto'],
        ['tipo' => 'pro', 'dias' => 7],
    ],
];
