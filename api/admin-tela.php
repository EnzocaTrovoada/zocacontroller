<?php
/**
 * O código das telas de admin, só pra conta admin.
 *
 * Ele morava no index.html, que é público: qualquer visitante lia como a
 * administração funciona, com a conta da comissão dos parceiros junto. Agora
 * a página pede isto com a chave, e quem não é admin leva 404.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = exige_painel();

$st = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$st->execute([(int) $quem['usuario_id']]);
if (!(int) $st->fetchColumn()) {
    json_saida(['erro' => 'Não encontrado.'], 404);
}

$css = (string) file_get_contents(__DIR__ . '/admin/tela.css');
$js  = (string) file_get_contents(__DIR__ . '/admin/tela.js');

header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: no-store, private');

echo "(function () {\n"
   . "  const s = document.createElement('style');\n"
   . '  s.textContent = ' . json_encode($css, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
   . "  document.head.appendChild(s);\n"
   . "})();\n\n"
   . $js;
