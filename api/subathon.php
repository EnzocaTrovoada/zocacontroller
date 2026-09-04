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
    json_saida($r, empty($r['ok']) ? 400 : 200);
}

// ---------- configurar ----------
$quem = exige_painel();

$slug  = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) ($d['slug'] ?? ''))));
$token = trim((string) ($d['token'] ?? ''));

if ($slug === '' || $token === '') {
    json_saida(['erro' => 'Cole o link do subathon e o código de dono dele.'], 400);
}

$valores = [];
foreach (CAMPOS as $campo) {
    $valores[$campo] = max(0, min(86400, (int) ($d[$campo] ?? 0)));
}

db()->prepare(
    'INSERT INTO subathon (usuario_id, slug, token, ligado, seg_sub1, seg_sub2, seg_sub3,
                           seg_bits, seg_follow, seg_real, teto_evento)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE slug = VALUES(slug), token = VALUES(token), ligado = VALUES(ligado),
          seg_sub1 = VALUES(seg_sub1), seg_sub2 = VALUES(seg_sub2), seg_sub3 = VALUES(seg_sub3),
          seg_bits = VALUES(seg_bits), seg_follow = VALUES(seg_follow), seg_real = VALUES(seg_real),
          teto_evento = VALUES(teto_evento)'
)->execute([
    $quem['usuario_id'], $slug, $token, empty($d['desligar']) ? 1 : 0,
    $valores['seg_sub1'], $valores['seg_sub2'], $valores['seg_sub3'],
    $valores['seg_bits'], $valores['seg_follow'], $valores['seg_real'],
    $valores['teto_evento'] ?: 7200,
]);

/* A legenda do overlay vem daqui: quem define quanto vale uma sub é esta
   tabela, então ela é publicada junto. Falhar aqui NÃO derruba a gravação —
   a configuração já está salva, e o overlay pega na próxima. */
$aviso = null;
$pub = subathon_publica_valores([
    'slug' => $slug, 'token' => $token,
    'seg_sub1' => $valores['seg_sub1'], 'seg_sub2' => $valores['seg_sub2'],
    'seg_sub3' => $valores['seg_sub3'], 'seg_bits' => $valores['seg_bits'],
    'seg_follow' => $valores['seg_follow'], 'seg_real' => $valores['seg_real'],
]);
if (empty($pub['ok'])) {
    $aviso = 'Salvei, mas não consegui atualizar a legenda no overlay: ' . $pub['erro'];
}

json_saida(['ok' => true, 'aviso' => $aviso]);
