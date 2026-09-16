<?php
/**
 * Conquista: Tem torcida.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'seguido',
    'nome'      => 'Tem torcida',
    'descricao' => 'Tenha 10 pessoas te seguindo aqui no site.',
    'icone'     => '⭐',
    'grupo'     => 'comunidade',
    'meta'      => 10,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM feed_seguidores WHERE seguido_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'pro', 'dias' => 7],
];
