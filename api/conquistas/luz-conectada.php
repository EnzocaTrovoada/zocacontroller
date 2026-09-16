<?php
/**
 * Conquista: Faça-se a luz.
 *
 * Contrato em _MODELO.php.
 */

return [
    'id'        => 'luz-conectada',
    'nome'      => 'Faça-se a luz',
    'descricao' => 'Conecte as suas lâmpadas pra o chat mudar a cor com !luz.',
    'icone'     => '💡',
    'grupo'     => 'live',
    'meta'      => 1,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM luzes_contas WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'overlays', 'quantos' => 1],
];
