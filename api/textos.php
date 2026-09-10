<?php
/**
 * Os textos que o administrador reescreveu.
 *
 * GET   — público, devolve { resumo: "texto novo" }. Público porque a
 *         página inicial também usa, e ela é vista por quem não entrou.
 * POST  — só admin: salva ou apaga uma substituição.
 *
 * A chave é o resumo (md5 cortado) do texto ORIGINAL. Ver o comentário do
 * sql/029-textos.sql para o porquê — e para o efeito colateral proposital:
 * frase reescrita no código perde a edição, porque provavelmente mudou de
 * sentido.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';

cors();

const TEXTO_MAX = 2000;

function texto_chave(string $original): string
{
    return substr(md5(trim($original)), 0, 12);
}

/* ------------------------------------------------------------------ *
 *  Leitura pública
 * ------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    /* DEVOLVE MAPEADO PELO TEXTO ORIGINAL, NÃO PELO RESUMO.

       O resumo é md5, e md5 no navegador exigiria carregar uma
       implementação inteira só pra procurar uma frase num dicionário. Com o
       original como chave, a página compara string com string e pronto. O
       banco continua usando o resumo como chave primária, que é onde ela
       importa: TEXT não pode ser PRIMARY KEY. */
    $mapa = [];
    try {
        foreach (db()->query('SELECT original, valor FROM textos')->fetchAll() as $l) {
            $mapa[(string) $l['original']] = (string) $l['valor'];
        }
    } catch (Throwable $e) {
        /* Tabela nova: sem ela o site usa os textos do código, que é
           exatamente o comportamento certo. */
    }

    /* Um minuto de cache: texto do site muda raramente, e cada visita não
       precisa acordar o banco por causa disso. */
    header('Cache-Control: public, max-age=60');
    json_saida(['textos' => $mapa]);
}

/* ------------------------------------------------------------------ *
 *  Escrita — só admin
 * ------------------------------------------------------------------ */

$quem = exige_painel();

$ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
$ad->execute([(int) $quem['usuario_id']]);
if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

$d = corpo_json();
$acao = (string) ($d['acao'] ?? 'salvar');

if ($acao === 'listar') {
    /* A tela de administração precisa ver o original junto pra saber o que
       cada substituição está trocando. */
    $l = db()->query('SELECT chave, original, valor, atualizado_em FROM textos ORDER BY atualizado_em DESC')
             ->fetchAll(PDO::FETCH_ASSOC);
    json_saida(['textos' => $l]);
}

$original = trim((string) ($d['original'] ?? ''));
if ($original === '') json_saida(['erro' => 'Falta o texto original.'], 400);

$chave = texto_chave($original);

if ($acao === 'apagar') {
    db()->prepare('DELETE FROM textos WHERE chave = ?')->execute([$chave]);
    json_saida(['ok' => true, 'chave' => $chave]);
}

$valor = trim((string) ($d['valor'] ?? ''));
if ($valor === '') json_saida(['erro' => 'O texto novo não pode ficar vazio.'], 400);
if (mb_strlen($valor) > TEXTO_MAX) json_saida(['erro' => 'Texto grande demais.'], 400);

/* Igual ao original não é substituição: é ruído na tabela e uma linha a
   mais pra alguém se perguntar depois por que existe. */
if ($valor === $original) {
    db()->prepare('DELETE FROM textos WHERE chave = ?')->execute([$chave]);
    json_saida(['ok' => true, 'chave' => $chave, 'igual' => true]);
}

db()->prepare(
    'INSERT INTO textos (chave, original, valor) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE valor = VALUES(valor), original = VALUES(original)'
)->execute([$chave, mb_substr($original, 0, TEXTO_MAX), $valor]);

json_saida(['ok' => true, 'chave' => $chave]);
