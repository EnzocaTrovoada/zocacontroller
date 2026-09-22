<?php
/**
 * A cor de acento que cada Pro escolhe pra si.
 *
 * GET           → { cor, pode }
 * POST {cor}    → guarda. Cor vazia volta ao padrão.
 *
 * UMA COR, NUNCA CSS.
 *
 * Deixar a pessoa colar o CSS que quiser parece generoso e é um buraco:
 * um seletor de atributo com background-image manda o que está na tela
 * pra fora sem script nenhum, e um display:none no aviso de cobrança
 * quebra o site de um jeito que chega como bug pro dono. Aqui entra um
 * hexadecimal de seis dígitos, conferido letra por letra, e nada mais.
 *
 * E só o acento. Fundo e texto continuam do tema: é neles que a
 * legibilidade morre, e o acento sozinho já muda a cara do site inteiro.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';

cors();
$quem = exige_painel();
$uid  = (int) $quem['usuario_id'];

/** A cor guardada, ou '' pra quem nunca escolheu. */
function tema_cor(int $uid): string
{
    try {
        $st = db()->prepare('SELECT cor_acento FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        return (string) $st->fetchColumn();
    } catch (Throwable $e) {
        return '';                  /* sem o SQL 061, todo mundo no padrão */
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_saida([
        'cor'  => tema_cor($uid),
        'pode' => (bool) limite($uid, 'cor_propria', 1),
    ]);
}

if (!limite($uid, 'cor_propria', 1)) {
    json_saida(['erro' => 'Escolher a cor do site é do Pro.'], 402);
}

$d   = corpo_json();
$cor = strtolower(trim((string) ($d['cor'] ?? '')));

/* Vazio é voltar ao padrão, e é caso normal — não erro. */
if ($cor !== '' && !preg_match('/^#[0-9a-f]{6}$/', $cor)) {
    json_saida(['erro' => 'Cor inválida.'], 400);
}

try {
    db()->prepare('UPDATE usuarios SET cor_acento = ? WHERE id = ?')->execute([$cor, $uid]);
} catch (PDOException $e) {
    json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 061 no banco.')], 500);
}

json_saida(['ok' => true, 'cor' => $cor]);
