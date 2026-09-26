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
 * Anota que esta conta usou este recurso hoje.
 *
 * MORA AQUI, E NÃO NUM ARQUIVO PRÓPRIO, POR UM MOTIVO APRENDIDO CARO.
 *
 * Ela nasceu no lib/uso.php e cada endereço que media passou a exigir
 * esse arquivo. Num deploy ele não subiu junto — e um require_once de
 * arquivo que falta é erro fatal: quatro endereços saíram do ar de uma
 * vez, em produção, por causa da contabilidade.
 *
 * O db.php todo arquivo já carrega, senão não fala com o banco. Aqui não
 * há arquivo novo pra esquecer de subir, e medir volta a ser incapaz de
 * derrubar o que está sendo medido — que era o que o comentário do outro
 * arquivo prometia e o desenho dele não cumpria.
 *
 * Os relatórios continuam no lib/uso.php: eles só o admin carrega, e se
 * aquele arquivo faltar o que quebra é uma tela de administração.
 */
function uso_marca(int $uid, string $recurso): void
{
    if ($uid <= 0 || $recurso === '') return;

    try {
        db()->prepare('INSERT IGNORE INTO uso (usuario_id, recurso, dia) VALUES (?, ?, CURDATE())')
            ->execute([$uid, mb_substr($recurso, 0, 30)]);
    } catch (Throwable $e) { /* sem o SQL 069: não se mede, e tudo segue */ }
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
/**
 * O segredo de um cron, sorteado em vez de inventado.
 *
 * O primeiro desenho pedia pra escrever um segredo no config e repetir ele
 * no comando do cron. Isso é pedir pra pessoa nomear uma coisa que o
 * computador sabe sortear melhor — e ainda herdar o risco de escolher algo
 * curto, ou de errar a cópia entre os dois lugares e descobrir semanas
 * depois, quando a rede não pegou ninguém.
 *
 * O config ainda ganha, quando preenchido: quem já tem um segredo lá não
 * pode ver o comando mudar debaixo dele. Sem nada no config, sorteia uma
 * vez e guarda — e a tela mostra o comando pronto pra copiar.
 */
function cron_segredo(string $nome): string
{
    $doConfig = trim((string) (cfg()[$nome . '_cron'] ?? ''));
    if ($doConfig !== '') return $doConfig;

    $chave = 'cron_' . $nome;

    try {
        $st = db()->prepare('SELECT valor FROM ajustes WHERE chave = ?');
        $st->execute([$chave]);
        $achado = trim((string) $st->fetchColumn());
        if ($achado !== '') return $achado;

        $novo = bin2hex(random_bytes(16));
        db()->prepare(
            'INSERT INTO ajustes (chave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = valor'
        )->execute([$chave, $novo]);

        /* Reler em vez de confiar no que acabei de escrever: com duas
           chamadas ao mesmo tempo, uma perde a corrida, e o valor que vale
           é o que ficou no banco — não o que esta chamada sorteou. */
        $st->execute([$chave]);
        return trim((string) $st->fetchColumn()) ?: $novo;
    } catch (Throwable $e) {
        /* Sem a tabela não há segredo, e sem segredo o cron responde 404.
           Devolver vazio é o que mantém o portão fechado. */
        return '';
    }
}

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
