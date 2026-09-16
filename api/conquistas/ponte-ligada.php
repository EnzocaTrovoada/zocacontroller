<?php
/**
 * Conquista: Chat no comando.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'ponte-ligada',
    'nome'      => 'Chat no comando',
    'descricao' => 'Coloque a ponte no OBS — é ela que deixa o chat mexer na sua live.',
    'icone'     => '🔌',
    'grupo'     => 'comeco',
    'meta'      => 1,
    'ordem'     => 2,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM estado_ao_vivo WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'overlays', 'quantos' => 1],
];
