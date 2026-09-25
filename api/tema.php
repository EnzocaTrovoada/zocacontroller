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

/**
 * Clareia a cor até ela se ler sobre o fundo do feed.
 *
 * O feed é escuro. Azul-marinho e roxo-escuro somem nele — e quem escolhe
 * não está olhando o feed dos outros na hora de escolher. A cor é clareada
 * só até passar de 4.5:1: a escolha continua sendo a da pessoa, com o
 * mínimo de mexida pra ela existir na tela.
 */
function cor_legivel(string $hex): string
{
    $rgb = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];

    $luz = static function (array $c): float {
        foreach ($c as &$v) {
            $v /= 255;
            $v = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };

    $lf = $luz([14, 21, 18]);                   /* o --caixa do tema escuro */

    for ($i = 0; $i <= 20; $i++) {
        $tenta = array_map(static fn($v) => $v + (255 - $v) * ($i / 20), $rgb);
        if (($luz($tenta) + 0.05) / ($lf + 0.05) >= 4.5) {
            return sprintf('#%02x%02x%02x', ...array_map('intval', array_map('round', $tenta)));
        }
    }
    return '#ffffff';
}

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
    $nick = '';
    try {
        $st = db()->prepare('SELECT cor_nick FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $nick = (string) $st->fetchColumn();
    } catch (Throwable $e) { /* sem o SQL 071 */ }

    json_saida([
        'cor'  => tema_cor($uid),
        'nick' => $nick,
        'pode' => (bool) limite($uid, 'cor_propria', 1),
    ]);
}

$d = corpo_json();

/* ---------- a cor do nome no feed ----------

   Mora aqui, junto da cor do site, porque pra quem usa é a mesma decisão:
   "as minhas cores". Dois endereços fariam procurar em dois lugares o que
   a pessoa pensa como uma coisa só.

   MAS A REGRA É OUTRA, e é por isso que tem trava própria. A cor do site
   só a pessoa vê; esta o feed inteiro vê. Aqui o servidor GARANTE o
   contraste em vez de avisar — não dá pra deixar alguém escolher um nome
   que some na tela dos outros.

   E vem ANTES da trava da cor do site: são dois recursos, e recusar um
   pelo motivo do outro seria mentir sobre o que falta. */
if (array_key_exists('nick', $d)) {
    if (!limite($uid, 'cor_nick', 1)) {
        json_saida(['erro' => 'A cor do nome é do Pro.'], 402);
    }

    $nick = strtolower(trim((string) $d['nick']));
    if ($nick !== '' && !preg_match('/^#[0-9a-f]{6}$/', $nick)) {
        json_saida(['erro' => 'Cor inválida.'], 400);
    }
    if ($nick !== '') $nick = cor_legivel($nick);

    try {
        db()->prepare('UPDATE usuarios SET cor_nick = ? WHERE id = ?')->execute([$nick, $uid]);
    } catch (PDOException $e) {
        json_saida(['erro' => erro_publico($e, 'Falta rodar o SQL 071 no banco.')], 500);
    }
    json_saida(['ok' => true, 'nick' => $nick]);
}

if (!limite($uid, 'cor_propria', 1)) {
    json_saida(['erro' => 'Escolher a cor do site é do Pro.'], 402);
}

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

uso_marca($uid, 'cor');

json_saida(['ok' => true, 'cor' => $cor]);
