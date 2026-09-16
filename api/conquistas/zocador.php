<?php
/**
 * Conquista: Zocador.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'zocador',
    'nome'      => 'Zocador',
    'descricao' => 'Poste 25 vezes no feed.',
    'icone'     => '📣',
    'grupo'     => 'feed',
    'meta'      => 25,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM posts WHERE usuario_id = ? AND escondido = 0');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'selo', 'slug' => 'zocador'],
];
