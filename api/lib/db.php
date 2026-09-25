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

/**
 * A mensagem de um erro que pode ir pra tela.
 *
 * Erro nosso — lançado de propósito, com texto escrito pra quem usa — passa.
 * Erro do banco ou do PHP não: ele carrega nome de tabela, caminho de
 * arquivo no servidor e o número da conta da hospedagem. Esse vai pro
 * registro do servidor, e a tela recebe o texto genérico.
 *
 * O PDOException é filho do RuntimeException no PHP, e por isso é barrado
 * pelo nome antes: pegar RuntimeException sozinho deixava passar SQL.
 */
function erro_publico(Throwable $e, string $generico = 'Algo deu errado aqui do nosso lado. Tente de novo em instantes.'): string
{
    if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
        return $e->getMessage();
    }
    error_log('[zc] ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    erro_anota($e);
    return $generico;
}

/**
 * Guarda o erro numa tabela, agrupado.
 *
 * O error_log continua sendo escrito acima: ele é a fonte completa. Isto
 * aqui é pra ter uma resposta DENTRO do site, porque numa hospedagem
 * compartilhada o log é quase inalcançável — e o efeito prático disso é
 * que o primeiro a saber que algo quebrou é quem escreve reclamando.
 *
 * NADA AQUI PODE ESTOURAR. Um erro ao anotar um erro viraria laço, e
 * derrubar a resposta por causa da contabilidade seria trocar um defeito
 * escondido por um defeito na cara — pior negócio.
 */
function erro_anota(Throwable $e): void
{
    try {
        /* O arquivo e a linha identificam o defeito; a mensagem varia com
           os dados e sozinha separaria o mesmo problema em mil linhas. */
        $onde = basename($e->getFile()) . ':' . $e->getLine();
        $assinatura = md5(get_class($e) . '|' . $onde);

        db()->prepare(
            'INSERT INTO erros (assinatura, tipo, mensagem, onde)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantos = quantos + 1, ultimo = NOW(),
                 mensagem = VALUES(mensagem)'
        )->execute([
            $assinatura,
            mb_substr(get_class($e), 0, 40),
            mb_substr($e->getMessage(), 0, 400),
            mb_substr($onde, 0, 160),
        ]);
    } catch (Throwable $x) { /* sem tabela, ou o banco é o próprio problema */ }
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
