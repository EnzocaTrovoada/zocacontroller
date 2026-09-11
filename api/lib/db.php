<?php

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $caminho = __DIR__ . '/../config.php';
        if (!is_file($caminho)) {
            http_response_code(500);
            exit('config.php ausente');
        }
        $c = require $caminho;
    }
    return $c;
}

/**
 * O endereço da API, com https e sem barra no fim.
 *
 * Vem do config; sem ele, o endereço fixo. Faltando no config, cada lugar
 * montava um endereço pela metade ("/selos.php") e o navegador completava
 * com o domínio do site, onde o arquivo não existe — o desenho do selo, o
 * som do alerta e o aviso de pagamento do Mercado Pago iam pra lugar nenhum.
 *
 * Nunca do cabeçalho Host: ele é de quem chama, e uma resposta guardada em
 * cache levaria o endereço inventado pra todo mundo.
 */
function api_base(): string
{
    $b = rtrim((string) (cfg()['api_base'] ?? ''), '/');
    return $b !== '' ? $b : 'https://api.zocahop.com';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg()['db'];
        $pdo = new PDO(
            "mysql:host={$d['host']};dbname={$d['nome']};charset=utf8mb4",
            $d['usuario'],
            $d['senha'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function json_saida($dados, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Devolve o trabalho pesado para depois da resposta (LiteSpeed da Hostinger). */
function responder_e_continuar(): void
{
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}
