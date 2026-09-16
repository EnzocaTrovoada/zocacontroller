<?php
/**
 * Conquista: Primeira overlay.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'primeiro-overlay',
    'nome'      => 'Primeira overlay',
    'descricao' => 'Crie a sua primeira overlay.',
    'icone'     => '🎬',
    'grupo'     => 'comeco',
    'meta'      => 1,
    'ordem'     => 1,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM perfis WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'overlays', 'quantos' => 1],
];
