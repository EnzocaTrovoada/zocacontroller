<?php
/**
 * Subathon automático.
 *
 * O overlay mora no relogio.zocahop.com e guarda "quando acaba". Somar tempo
 * é ler quanto falta, somar, e regravar — e quem faz isso é este servidor,
 * porque o código de dono do overlay não pode viajar numa URL que aparece em
 * print. Servidor falando com servidor não passa por navegador nenhum.
 *
 * GET               → configuração e os últimos eventos
 * POST {config}     → muda as regras (quanto cada coisa soma)
 * POST {acao:somar} → soma tempo. É a ponte quem chama, quando vê sub ou bits
 *                     no chat.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();
$quem = quem_chama();

require_once __DIR__ . '/lib/subathon-somar.php';

const CAMPOS = SUB_CAMPOS;

// ---------- leitura ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $c = subathon_config($quem['usuario_id']);
    if (!$c) {
        json_saida(['ligado' => false, 'configurado' => false]);
    }

    $st = db()->prepare(
        'SELECT tipo, quem, detalhe, segundos,
                TIMESTAMPDIFF(SECOND, criado_em, NOW()) AS ha_segundos
           FROM subathon_eventos WHERE usuario_id = ? ORDER BY id DESC LIMIT 30'
    );
    $st->execute([$quem['usuario_id']]);

    json_saida([
        'configurado' => true,
        'ligado'      => (bool) $c['ligado'],
        'perfil_id'   => $c['perfil_id'] ?? null,
        'viewers_alvo'  => (int) ($c['viewers_alvo'] ?? 0),
        'viewers_seg'   => (int) ($c['viewers_seg'] ?? 0),
        'viewers_passo' => (int) ($c['viewers_passo'] ?? 0),
        'slug'        => $c['slug'],
        'regras'      => array_map('intval', array_intersect_key($c, array_flip(CAMPOS))),
        'eventos'     => $st->fetchAll(),
    ]);
}

$d = corpo_json();

// ---------- somar (a ponte chama) ----------
if (($d['acao'] ?? '') === 'somar') {
    if ($quem['tipo'] !== 'painel') {
        json_saida(['erro' => 'Só a ponte soma tempo.'], 403);
    }
    trava('subathon', 120, 60);

    // A conta mora na lib porque o EventSub tambem soma por la, quando
    // alguem segue. Duas copias acabariam divergindo.
    $r = subathon_somar($quem['usuario_id'], $d);

    /* 503 quando foi só um soluço (o overlay não respondeu). A ponte trata
       503 como "tento de novo já"; 400 ela entende como "esse evento não
       serve" e 'parar' como "desiste por um bom tempo". Mandar 400 pra tudo
       fazia um soluço calar o subathon inteiro. */
    if (!empty($r['ok']))           $http = 200;
    elseif (!empty($r['parar']))    $http = 400;
    elseif (!empty($r['transitorio'])) $http = 503;
    else                            $http = 400;

    json_saida($r, $http);
}

// ---------- configurar ----------
$quem = exige_painel();

/*
 * DOIS JEITOS DE APONTAR O CRONOMETRO.
 *
 * O novo: perfil_id, um overlay de subathon desta conta. A pessoa escolhe
 * numa lista e pronto.
 *
 * O velho: slug + codigo de dono do site do relogio, copiados na mao. Fica
 * aceito porque quem ja configurou nao pode perder o subathon no meio de uma
 * live so porque eu mudei de ideia.
 */
$perfil_id = (int) ($d['perfil_id'] ?? 0);
$slug  = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) ($d['slug'] ?? ''))));
$token = trim((string) ($d['token'] ?? ''));

if ($perfil_id > 0) {
    $st = db()->prepare("SELECT id FROM perfis WHERE id = ? AND usuario_id = ? AND tipo = 'subathon'");
    $st->execute([$perfil_id, $quem['usuario_id']]);
    if (!$st->fetchColumn()) {
        json_saida(['erro' => 'Esse overlay de subathon não é seu, ou não existe mais.'], 400);
    }
    $slug = null;
    $token = null;
} elseif ($slug === '' || $token === '') {
    json_saida(['erro' => 'Escolha qual overlay de subathon o tempo vai alimentar.'], 400);
}

/* A meta de viewers: quantos, quanto soma, e de quanto sobe o próximo alvo. */
$vAlvo  = max(0, min(1000000, (int) ($d['viewers_alvo'] ?? 0)));
$vSeg   = max(0, min(86400, (int) ($d['viewers_seg'] ?? 0)));
$vPasso = max(0, min(1000000, (int) ($d['viewers_passo'] ?? 0)));

$valores = [];
foreach (CAMPOS as $campo) {
    $valores[$campo] = max(0, min(86400, (int) ($d[$campo] ?? 0)));
}

db()->prepare(
    'INSERT INTO subathon (usuario_id, perfil_id, slug, token, ligado, seg_sub1, seg_sub2, seg_sub3,
                           seg_bits, seg_follow, seg_real, teto_evento,
                           viewers_alvo, viewers_seg, viewers_passo)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE perfil_id = VALUES(perfil_id),
          viewers_alvo = VALUES(viewers_alvo), viewers_seg = VALUES(viewers_seg),
          viewers_passo = VALUES(viewers_passo),
          slug = VALUES(slug), token = VALUES(token), ligado = VALUES(ligado),
          seg_sub1 = VALUES(seg_sub1), seg_sub2 = VALUES(seg_sub2), seg_sub3 = VALUES(seg_sub3),
          seg_bits = VALUES(seg_bits), seg_follow = VALUES(seg_follow), seg_real = VALUES(seg_real),
          teto_evento = VALUES(teto_evento)'
)->execute([
    $quem['usuario_id'], $perfil_id ?: null, $slug, $token, empty($d['desligar']) ? 1 : 0,
    $valores['seg_sub1'], $valores['seg_sub2'], $valores['seg_sub3'],
    $valores['seg_bits'], $valores['seg_follow'], $valores['seg_real'],
    $valores['teto_evento'] ?: 7200,
    $vAlvo, $vSeg, $vPasso,
]);

/* A legenda do overlay vem daqui: quem define quanto vale uma sub é esta
   tabela, então ela é publicada junto. Falhar aqui NÃO derruba a gravação —
   a configuração já está salva, e o overlay pega na próxima. */
$aviso = null;
$pub = subathon_publica_valores([
    'usuario_id' => $quem['usuario_id'], 'perfil_id' => $perfil_id ?: null,
    'slug' => $slug, 'token' => $token,
    'seg_sub1' => $valores['seg_sub1'], 'seg_sub2' => $valores['seg_sub2'],
    'seg_sub3' => $valores['seg_sub3'], 'seg_bits' => $valores['seg_bits'],
    'seg_follow' => $valores['seg_follow'], 'seg_real' => $valores['seg_real'],
]);
if (empty($pub['ok'])) {
    $aviso = 'Salvei, mas não consegui atualizar a legenda no overlay: ' . $pub['erro'];
}

json_saida(['ok' => true, 'aviso' => $aviso]);
