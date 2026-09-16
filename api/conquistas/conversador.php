<?php
/**
 * Conquista: Bom de papo.
 *
 * Contrato em _MODELO.php.
 *
 * Só comentário no post dos outros: responder o próprio post não é conversa.
 */

return [
    'id'        => 'conversador',
    'nome'      => 'Bom de papo',
    'descricao' => 'Comente 20 vezes nos posts de outras pessoas.',
    'icone'     => '💬',
    'grupo'     => 'comunidade',
    'meta'      => 20,

    'progresso' => function (int $uid): int {
        $st = db()->prepare('SELECT COUNT(*) FROM post_comentarios c JOIN posts p ON p.id = c.post_id
              WHERE c.usuario_id = ? AND c.escondido = 0 AND p.usuario_id <> c.usuario_id');
        $st->execute([$uid]);
        return (int) $st->fetchColumn();
    },

    'premio' => ['tipo' => 'overlays', 'quantos' => 1],
];
