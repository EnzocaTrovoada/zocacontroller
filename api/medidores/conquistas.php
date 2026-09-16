<?php
/**
 * Medidor: Conquistas ganhas.
 *
 * Contrato em _MODELO.php.
 *
 * Serve pra conquista de colecionador. Conta as ganhas até a conferência
 * anterior: uma ganha agora entra na conta da próxima vez.
 */

return [
    'id'      => 'conquistas',
    'nome'    => 'Conquistas ganhas',
    'unidade' => ['conquista', 'conquistas'],

    'contar' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM conquistas_usuario WHERE usuario_id = ?');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },
];
