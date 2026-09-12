<?php
/**
 * O depósito de imagens pequenas, na parte que outros arquivos precisam.
 *
 * Separado do api/imagens.php porque aquele é um endereço: incluir um
 * endereço pra reaproveitar uma função executa o endereço junto.
 */
require_once __DIR__ . '/db.php';

const IMG_DIR   = __DIR__ . '/../../imagens';
const IMG_MAX   = 150;
const IMG_BYTES = 262144;       /* 256 KB — é ícone, não papel de parede */

const IMG_TIPOS = [
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'image/jpeg' => 'jpg',
];

function img_caminho(string $arquivo): string
{
    return IMG_DIR . '/' . basename($arquivo);
}

/**
 * Copia imagens de uma conta pra outra e devolve [id velho => id novo].
 *
 * Aplicar um modelo de speedrun de outra pessoa precisa disto: o ícone é
 * servido pela chave pública da overlay de quem é dono do arquivo, então
 * sem a cópia o ícone do modelo não apareceria pra mais ninguém.
 */
function img_copia(int $de, int $para, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || $de === $para) return [];

    $st = db()->prepare('SELECT COUNT(*) FROM imagens WHERE usuario_id = ?');
    $st->execute([$para]);
    $cabe = IMG_MAX - (int) $st->fetchColumn();
    if ($cabe <= 0) return [];

    $vaz = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id, arquivo, nome, bytes, tipo FROM imagens
                          WHERE usuario_id = ? AND id IN ($vaz)");
    $st->execute(array_merge([$de], $ids));

    $mapa = [];
    $ins = db()->prepare('INSERT INTO imagens (usuario_id, arquivo, nome, bytes, tipo) VALUES (?, ?, ?, ?, ?)');

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        if ($cabe-- <= 0) break;

        $ext  = strtolower(pathinfo((string) $i['arquivo'], PATHINFO_EXTENSION));
        $novo = bin2hex(random_bytes(16)) . '.' . ($ext ?: 'png');
        if (!@copy(img_caminho((string) $i['arquivo']), img_caminho($novo))) continue;

        $ins->execute([$para, $novo, $i['nome'], (int) $i['bytes'], (string) $i['tipo']]);
        $mapa[(int) $i['id']] = (int) db()->lastInsertId();
    }
    return $mapa;
}
