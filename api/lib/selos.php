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

function selo_url(int $id): string
{
    return rtrim((string) (cfg()['api_base'] ?? ''), '/') . '/selos.php?a=img&id=' . $id;
}

function selo_lista(): array
{
    try {
        $st = db()->query('SELECT id, slug, nome, cor, arquivo, ordem, ligado FROM selos ORDER BY ordem, id');
    } catch (Throwable $e) {
        return [];
    }

    return array_map(fn($s) => [
        'id'     => (int) $s['id'],
        'slug'   => (string) $s['slug'],
        'nome'   => (string) $s['nome'],
        'cor'    => (string) $s['cor'],
        'img'    => $s['arquivo'] ? selo_url((int) $s['id']) : null,
        'ordem'  => (int) $s['ordem'],
        'ligado' => (int) $s['ligado'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Os selos de cada uma destas contas, agrupados pelo id da conta. */
function selos_de(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];

    $vaz = implode(',', array_fill(0, count($ids), '?'));

    try {
        $st = db()->prepare(
            "SELECT us.usuario_id, s.id, s.slug, s.nome, s.cor, s.arquivo
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
            'img'  => $r['arquivo'] ? selo_url((int) $r['id']) : null,
        ];
    }
    return $saida;
}
