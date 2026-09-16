<?php
/**
 * Conquista: Palavra mágica.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'primeiro-comando',
    'nome'      => 'Palavra mágica',
    'descricao' => 'Crie um comando próprio pro seu chat, tipo !brb.',
    'icone'     => '⌨️',
    'grupo'     => 'comeco',
    'meta'      => 1,
    'ordem'     => 3,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM comandos WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'overlays', 'quantos' => 1],
];
