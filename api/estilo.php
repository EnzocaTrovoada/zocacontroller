<?php
/**
 * CSS extra do site, escrito pelo administrador.
 *
 * GET  — público, devolve o CSS.
 * POST — só admin, salva.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();

const ESTILO_MAX = 20000;

function estilo_le(): string
{
    try {
        $st = db()->prepare('SELECT valor FROM ajustes WHERE chave = ?');
        $st->execute(['css']);
        return (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Cache-Control: public, max-age=60');
    json_saida(['css' => estilo_le()]);
}

$quem = exige_painel();

$ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$ad->execute([(int) $quem['usuario_id']]);
if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

$css = (string) (corpo_json()['css'] ?? '');
if (mb_strlen($css) > ESTILO_MAX) json_saida(['erro' => 'CSS grande demais.'], 400);

// </style> aqui fecharia a tag e o resto viraria HTML executável na página.
$css = str_ireplace(['</style', '<script', 'javascript:'], '', $css);

db()->prepare(
    'INSERT INTO ajustes (chave, valor) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
)->execute(['css', $css]);

json_saida(['ok' => true]);
