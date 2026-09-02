<?php
/**
 * Palpites — os Predictions nativos da Twitch, com pontos de canal de verdade.
 *
 * Não inventamos moeda própria de propósito: o viewer já tem pontos de canal,
 * e duas moedas na mesma tela é uma moeda que ninguém entende.
 *
 * GET                                       → o palpite aberto, se houver
 * POST {acao:criar, titulo, opcoes[], seg}  → abre
 * POST {acao:resolver, vencedora}           → paga
 * POST {acao:cancelar}                      → devolve os pontos
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/twitch.php';

cors();
$quem = quem_chama();
exige_poder($quem, 'palpite');   // mexer em pontos é só do dono do canal

// Limites da própria Twitch. Melhor recusar aqui, com recado em português,
// do que deixar a API responder um erro em inglês que ninguém lê.
const TITULO_MAX  = 45;
const OPCAO_MAX   = 25;
const OPCOES_MIN  = 2;
const OPCOES_MAX  = 10;
const JANELA_MIN  = 30;
const JANELA_MAX  = 1800;

try {
    $bid = tw_broadcaster_id($quem['usuario_id']);

    // ---------- o que está aberto ----------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        [$http, $r] = tw_helix($quem['usuario_id'], 'GET', '/predictions',
            ['broadcaster_id' => $bid, 'first' => 1]);

        if ($http !== 200) {
            json_saida(['erro' => 'Não consegui ler os palpites.'], 502);
        }
        $p = $r['data'][0] ?? null;
        if (!$p || !in_array($p['status'], ['ACTIVE', 'LOCKED'], true)) {
            json_saida(['aberto' => null]);
        }

        json_saida(['aberto' => [
            'id'      => $p['id'],
            'titulo'  => $p['title'],
            'estado'  => $p['status'],
            'opcoes'  => array_map(fn($o) => [
                'id'      => $o['id'],
                'titulo'  => $o['title'],
                'pontos'  => $o['channel_points'] ?? 0,
                'gente'   => $o['users'] ?? 0,
            ], $p['outcomes'] ?? []),
        ]]);
    }

    // ---------- mexer ----------
    trava('palpite', 20, 60);
    $d = corpo_json();
    $acao = strtolower(trim((string) ($d['acao'] ?? '')));

    if ($acao === 'criar') {
        $titulo = trim((string) ($d['titulo'] ?? ''));
        if ($titulo === '') {
            json_saida(['erro' => 'Escreva a pergunta do palpite.'], 400);
        }
        if (mb_strlen($titulo) > TITULO_MAX) {
            json_saida(['erro' => 'A pergunta passa de ' . TITULO_MAX . ' caracteres.'], 400);
        }

        // Sem opções? Sim ou Não. É o caso comum, e assim não tem sintaxe
        // nenhuma para o mod decorar.
        $opcoes = array_values(array_filter(array_map(
            fn($o) => trim((string) $o), (array) ($d['opcoes'] ?? [])
        ), fn($o) => $o !== ''));
        if (!$opcoes) {
            $opcoes = ['Sim', 'Não'];
        }

        if (count($opcoes) < OPCOES_MIN || count($opcoes) > OPCOES_MAX) {
            json_saida(['erro' => 'Precisa de ' . OPCOES_MIN . ' a ' . OPCOES_MAX . ' opções.'], 400);
        }
        foreach ($opcoes as $o) {
            if (mb_strlen($o) > OPCAO_MAX) {
                json_saida(['erro' => 'A opção "' . $o . '" passa de ' . OPCAO_MAX . ' caracteres.'], 400);
            }
        }

        $janela = (int) ($d['segundos'] ?? 120);
        $janela = max(JANELA_MIN, min(JANELA_MAX, $janela));

        [$http, $r] = tw_helix($quem['usuario_id'], 'POST', '/predictions', [], [
            'broadcaster_id'   => $bid,
            'title'            => $titulo,
            'outcomes'         => array_map(fn($o) => ['title' => $o], $opcoes),
            'prediction_window' => $janela,
        ]);

        if ($http !== 200 || empty($r['data'][0]['id'])) {
            $msg = $r['message'] ?? "http $http";
            if ($http === 400 && stripos($msg, 'already') !== false) {
                $msg = 'Já existe um palpite aberto. Resolva ou cancele antes.';
            }
            json_saida(['erro' => $msg], 400);
        }

        $p = $r['data'][0];
        db()->prepare(
            'INSERT INTO palpites (usuario_id, twitch_id, titulo, opcoes, estado, quem)
                  VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE estado = VALUES(estado)'
        )->execute([
            $quem['usuario_id'], $p['id'], $titulo,
            json_encode($opcoes, JSON_UNESCAPED_UNICODE), $p['status'], $quem['nome'],
        ]);

        json_saida(['ok' => true, 'id' => $p['id'], 'opcoes' => array_map(
            fn($o) => ['id' => $o['id'], 'titulo' => $o['title']], $p['outcomes']
        )]);
    }

    if ($acao === 'resolver' || $acao === 'cancelar' || $acao === 'travar') {
        $id = trim((string) ($d['id'] ?? ''));
        if ($id === '') {
            // Sem id, resolve o que estiver aberto.
            [$http, $r] = tw_helix($quem['usuario_id'], 'GET', '/predictions',
                ['broadcaster_id' => $bid, 'first' => 1]);
            $id = $r['data'][0]['id'] ?? '';
            if ($id === '') {
                json_saida(['erro' => 'Não há palpite aberto.'], 400);
            }
        }

        $corpo = ['broadcaster_id' => $bid, 'id' => $id];
        if ($acao === 'resolver') {
            $venc = trim((string) ($d['vencedora'] ?? ''));
            if ($venc === '') {
                json_saida(['erro' => 'Diga qual opção ganhou.'], 400);
            }
            $corpo['status'] = 'RESOLVED';
            $corpo['winning_outcome_id'] = $venc;
        } else {
            $corpo['status'] = $acao === 'travar' ? 'LOCKED' : 'CANCELED';
        }

        [$http, $r] = tw_helix($quem['usuario_id'], 'PATCH', '/predictions', [], $corpo);
        if ($http !== 200) {
            json_saida(['erro' => $r['message'] ?? "http $http"], 400);
        }

        db()->prepare('UPDATE palpites SET estado = ?, fechado_em = NOW() WHERE twitch_id = ?')
            ->execute([$corpo['status'], $id]);

        json_saida(['ok' => true, 'estado' => $corpo['status']]);
    }

    json_saida(['erro' => 'Ação desconhecida.'], 400);

} catch (RuntimeException $e) {
    json_saida(['erro' => $e->getMessage()], 400);
}
