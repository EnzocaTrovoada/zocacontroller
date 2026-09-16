<?php
/**
 * Os selos, na parte que outros arquivos precisam ler.
 *
 * Fica separado do api/selos.php porque aquele é um endereço: incluir um
 * endereço pra reaproveitar uma função executa o endereço junto.
 */
require_once __DIR__ . '/db.php';

const SELO_DIR   = __DIR__ . '/../../selos';
const SELO_BYTES = 262144;

const SELO_TIPOS = [
    'image/png'     => 'png',
    'image/webp'    => 'webp',
    'image/gif'     => 'gif',
    'image/svg+xml' => 'svg',
];

function selo_caminho(string $arquivo): string
{
    return SELO_DIR . '/' . basename($arquivo);
}

/* O v muda a cada desenho novo (o nome do arquivo é sorteado no envio).
   Sem ele, o navegador guardava o desenho velho por um dia inteiro depois
   da troca — e o ajuste automático parecia não ter feito nada. */
function selo_url(int $id, string $arquivo = ''): string
{
    return api_base() . '/selos.php?a=img&id=' . $id
         . ($arquivo !== '' ? '&v=' . substr(preg_replace('/[^a-f0-9]/', '', $arquivo), 0, 10) : '');
}

/** O acerto fino de cada selo, com o padrão pra quem ainda não rodou o SQL 050. */
function selo_ajuste(array $s): array
{
    return [
        'escala' => max(0.5, min(2.0, (float) ($s['escala'] ?? 1))),
        'ajuste' => max(-8, min(8, (int) ($s['ajuste_y'] ?? 0))),
    ];
}

function selo_lista(): array
{
    try {
        /* SELECT * e não a lista de colunas: a descrição chegou depois, e
           assim quem ainda não rodou o SQL dela continua vendo os selos. */
        $st = db()->query('SELECT * FROM selos ORDER BY ordem, id');
    } catch (Throwable $e) {
        return [];
    }

    return array_map(fn($s) => [
        'id'     => (int) $s['id'],
        'slug'   => (string) $s['slug'],
        'nome'   => (string) $s['nome'],
        'cor'    => (string) $s['cor'],
        'img'    => $s['arquivo'] ? selo_url((int) $s['id'], (string) $s['arquivo']) : null,
        'dica'   => (string) ($s['descricao'] ?? ''),
        'ordem'  => (int) $s['ordem'],
        'ligado' => (int) $s['ligado'],
    ] + selo_ajuste($s), $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Os selos de cada uma destas contas, agrupados pelo id da conta. */
function selos_de(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];

    $vaz = implode(',', array_fill(0, count($ids), '?'));

    try {
        $st = db()->prepare(
            "SELECT us.usuario_id, s.*
               FROM usuario_selos us JOIN selos s ON s.id = us.selo_id
              WHERE s.ligado = 1 AND us.usuario_id IN ($vaz)
              ORDER BY s.ordem, s.id"
        );
        $st->execute($ids);
    } catch (Throwable $e) {
        return [];
    }

    $saida = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $saida[(int) $r['usuario_id']][] = [
            'slug' => (string) $r['slug'],
            'nome' => (string) $r['nome'],
            'cor'  => (string) $r['cor'],
            'img'  => $r['arquivo'] ? selo_url((int) $r['id'], (string) $r['arquivo']) : null,
            'dica' => (string) ($r['descricao'] ?? ''),
        ] + selo_ajuste($r);
    }
    return $saida;
}
