<?php
/**
 * Os inscritos do canal no YouTube.
 *
 * SEM OAUTH, DE PROPÓSITO. A contagem de inscritos é dado público, então uma
 * chave de API do servidor basta. O caminho de OAuth pediria verificação do
 * app pelo Google e tem teto de 100 usuários enquanto não for verificado — e
 * esse teto vale pra vida inteira do projeto, não se reseta.
 *
 * ---------------------------------------------------------------------
 * O NÚMERO VEM ARREDONDADO, E NÃO TEM JEITO.
 *
 * O YouTube arredonda o subscriberCount para três algarismos significativos,
 * inclusive para o dono do canal pedindo o próprio número: 123.456 chega como
 * 123000. Na prática o valor anda de 10 em 10 acima de mil, de 100 em 100
 * acima de dez mil, de 1000 em 1000 acima de cem mil.
 *
 * Isso é regra do YouTube e não existe endpoint que devolva o exato — a
 * Analytics API só tem ganhos e perdas por período, não o total. Quem for
 * usar meta de inscritos precisa saber disso antes, não depois.
 */
require_once __DIR__ . '/db.php';

const YT_API = 'https://www.googleapis.com/youtube/v3';

function yt_chave(): string
{
    $c = cfg()['youtube']['api_key'] ?? '';
    if ($c === '') throw new RuntimeException('O YouTube não está configurado neste servidor.');
    return $c;
}

function yt_http(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, json_decode((string) $r, true)];
}

/**
 * Resolve um @handle no id do canal, e guarda.
 *
 * Cada resolução custa uma unidade de cota, e o id nunca muda — resolver a
 * cada consulta seria queimar metade da cota pra sempre descobrir a mesma
 * coisa.
 */
function yt_resolve(int $usuario_id, string $handle): array
{
    $handle = ltrim(trim($handle), '@');
    if ($handle === '') return ['ok' => false, 'erro' => 'Escreva o @ do seu canal.'];

    /* Aceita tanto o @handle quanto o id cru (começa com UC e tem 24 chars):
       quem já sabe o id não devia ser obrigado a procurar o handle. */
    if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $handle)) {
        $url = YT_API . '/channels?part=statistics&id=' . rawurlencode($handle) . '&key=' . rawurlencode(yt_chave());
    } else {
        $url = YT_API . '/channels?part=statistics&forHandle=' . rawurlencode($handle) . '&key=' . rawurlencode(yt_chave());
    }

    [$http, $d] = yt_http($url);
    if ($http !== 200) {
        return ['ok' => false, 'erro' => 'O YouTube recusou (' . $http . '). Confira a chave do servidor.'];
    }
    $item = $d['items'][0] ?? null;
    if (!$item || empty($item['id'])) {
        return ['ok' => false, 'erro' => 'Não achei esse canal. Confira o @ — ele aparece no endereço do seu canal.'];
    }

    db()->prepare('UPDATE usuarios SET yt_canal = ?, yt_handle = ? WHERE id = ?')
        ->execute([(string) $item['id'], $handle, $usuario_id]);

    return ['ok' => true, 'canal' => (string) $item['id'], 'handle' => $handle,
            'inscritos' => yt_le_inscritos($item)];
}

/** O número dentro da resposta, ou null quando o canal esconde a contagem. */
function yt_le_inscritos(array $item): ?int
{
    $s = $item['statistics'] ?? [];
    /* Escondido é diferente de zero: mostrar zero seria mentir na tela. */
    if (!empty($s['hiddenSubscriberCount'])) return null;
    if (!isset($s['subscriberCount'])) return null;
    return (int) $s['subscriberCount'];
}

/** Quantos inscritos agora. Null = não deu pra saber. */
function yt_inscritos(int $usuario_id): ?int
{
    $st = db()->prepare('SELECT yt_canal FROM usuarios WHERE id = ?');
    $st->execute([$usuario_id]);
    $canal = (string) ($st->fetchColumn() ?: '');
    if ($canal === '') throw new RuntimeException('O canal do YouTube ainda não foi escolhido.');

    [$http, $d] = yt_http(YT_API . '/channels?part=statistics&id=' . rawurlencode($canal)
                          . '&key=' . rawurlencode(yt_chave()));
    if ($http !== 200) throw new RuntimeException('O YouTube respondeu ' . $http . '.');

    $item = $d['items'][0] ?? null;
    if (!$item) throw new RuntimeException('O YouTube não achou mais esse canal.');

    $n = yt_le_inscritos($item);
    if ($n === null) throw new RuntimeException('Este canal está com a contagem de inscritos escondida no YouTube.');
    return $n;
}
