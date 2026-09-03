<?php
/**
 * Quem pode chamar o quê.
 *
 * Duas identidades, nenhum cadastro:
 *   painel  — a chave do streamer, usada pela ponte e pelo painel na máquina dele
 *   mod     — um link por moderador, revogável um a um
 *
 * As duas viajam em cabeçalho, nunca em query, para não cair no log do
 * servidor nem no histórico do navegador.
 */
require_once __DIR__ . '/db.php';

/** Os overlays moram noutro domínio, então o navegador exige liberação explícita. */
function cors(): void
{
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    $permitidas = cfg()['origens'] ?? [];

    if ($origem !== '' && in_array($origem, $permitidas, true)) {
        header('Access-Control-Allow-Origin: ' . $origem);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, X-Chave, X-Mod');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Max-Age: 86400');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function ip_de_quem_chama(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Limite de tentativas.
 *
 * Só conta ERRO, nunca acerto. O objetivo é encarecer quem fica adivinhando
 * chave, e quem já tem a chave certa não está adivinhando nada — a ponte
 * sozinha faz dezenas de chamadas legítimas por minuto.
 */
function trava(string $rotulo, int $max = 30, int $janela = 60): void
{
    require_once __DIR__ . '/seguranca.php';
    if (!limite_ok($rotulo . ':' . ip_de_quem_chama(), $max, $janela)) {
        json_saida(['erro' => 'Muitas tentativas. Espere um minuto.'], 429);
    }
}

/** Registra uma chave recusada e barra o IP depois de poucas seguidas. */
function contar_erro(string $rotulo): void
{
    require_once __DIR__ . '/seguranca.php';
    if (!limite_ok('falha:' . $rotulo . ':' . ip_de_quem_chama(), 10, 300)) {
        json_saida(['erro' => 'Chave recusada vezes demais. Espere alguns minutos.'], 429);
    }
}

function hash_chave(string $bruta): string
{
    return hash('sha256', $bruta);
}

/**
 * Identifica quem está chamando.
 * Devolve ['usuario_id', 'tipo', 'nome', 'pode' => ['cena','audio','canal']].
 */
function quem_chama(): array
{
    $chave = $_SERVER['HTTP_X_CHAVE'] ?? '';
    $mod   = $_SERVER['HTTP_X_MOD']   ?? '';

    if ($chave !== '') {
        $st = db()->prepare('SELECT id FROM usuarios WHERE chave_painel = ? LIMIT 1');
        $st->execute([hash_chave($chave)]);
        $id = $st->fetchColumn();
        if ($id) {
            return [
                'usuario_id' => (int) $id,
                'tipo'       => 'painel',
                'nome'       => 'painel',
                'pode'       => ['cena' => true, 'audio' => true, 'canal' => true, 'palpite' => true],
            ];
        }
        contar_erro('painel');
        json_saida(['erro' => 'Chave inválida.'], 401);
    }

    if ($mod !== '') {
        $st = db()->prepare(
            'SELECT id, usuario_id, nome, pode_cena, pode_audio, pode_canal
               FROM convites_mod WHERE token_hash = ? AND revogado = 0 LIMIT 1'
        );
        $st->execute([hash_chave($mod)]);
        $c = $st->fetch();
        if ($c) {
            db()->prepare('UPDATE convites_mod SET ultimo_uso = NOW() WHERE id = ?')->execute([$c['id']]);
            return [
                'usuario_id' => (int) $c['usuario_id'],
                'tipo'       => 'mod',
                'nome'       => $c['nome'],
                'pode'       => [
                    'cena'    => (bool) $c['pode_cena'],
                    'audio'   => (bool) $c['pode_audio'],
                    'canal'   => (bool) $c['pode_canal'],
                    'palpite' => false,   // palpite mexe em pontos: só o dono
                ],
            ];
        }
        contar_erro('mod');
        json_saida(['erro' => 'Este link não vale mais.'], 401);
    }

    json_saida(['erro' => 'Faltou a chave de acesso.'], 401);
}

function exige_painel(): array
{
    $q = quem_chama();
    if ($q['tipo'] !== 'painel') {
        json_saida(['erro' => 'Só o streamer pode fazer isso.'], 403);
    }
    return $q;
}

function exige_poder(array $q, string $poder): void
{
    if (empty($q['pode'][$poder])) {
        json_saida(['erro' => 'Seu link não tem permissão para isso.'], 403);
    }
}

/** Corpo JSON da requisição, sempre array. */
function corpo_json(): array
{
    $cru = file_get_contents('php://input');
    $d = json_decode($cru ?: '[]', true);
    return is_array($d) ? $d : [];
}
