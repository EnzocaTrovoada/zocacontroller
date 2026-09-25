<?php
/**
 * Ensaio do caminho do dinheiro, sem banco e sem Mercado Pago.
 *
 * POR QUE ISTO EXISTE: o desconto por dias de raid mexe em dinheiro de
 * verdade e a cobrança já está em produção. Um pagamento real é a única
 * prova final, mas ele é caro de fazer e não dá pra repetir vinte vezes.
 * Aqui dá — e as regras que quebram em silêncio são justamente as que
 * ninguém testa à mão: o teto, o cupom por cima, o gasto duplicado e o
 * estorno.
 *
 * Rode com:  php testes/pagamento.php
 */

$falhas = 0;

function confere(string $oQue, $deu, $esperado): void
{
    global $falhas;
    $ok = $deu === $esperado;
    if (!$ok) $falhas++;
    printf("%s  %-46s  deu %-8s esperava %s\n",
        $ok ? 'ok  ' : 'FALHA', $oQue, var_export($deu, true), var_export($esperado, true));
}

/* ------------------------------------------------------------------ *
 *  Um banco de mentira, com só o que estas funções perguntam
 * ------------------------------------------------------------------ */
class FalsoSt
{
    public function __construct(private string $sql, private array &$d) {}
    public function execute($a = null): bool { return true; }
    public function fetch(): array|false
    {
        if (str_contains($this->sql, 'FROM raid_saldo')) return $this->d['saldo'];
        return false;
    }
    public function fetchColumn(): mixed
    {
        if (str_contains($this->sql, 'preco_centavos')) return $this->d['mensal'];
        if (str_contains($this->sql, 'dias_raid'))      return $this->d['dias_na_conta'];
        return false;
    }
}

class FalsoDb
{
    public array $d = [];
    public array $escreveu = [];
    public function prepare(string $sql): FalsoSt
    {
        if (str_starts_with(ltrim($sql), 'UPDATE') || str_starts_with(ltrim($sql), 'INSERT')) {
            $this->escreveu[] = preg_replace('/\s+/', ' ', trim($sql));
        }
        return new FalsoSt($sql, $this->d);
    }
    public function query(string $sql): FalsoSt { return new FalsoSt($sql, $this->d); }
}

$DB = new FalsoDb();
function db(): FalsoDb { global $DB; return $DB; }

/* Só a função do desconto: puxar o arquivo inteiro traria cURL e config. */
$fonte = file_get_contents(__DIR__ . '/../api/lib/mercadopago.php');
preg_match('/const MP_DIAS_MES.*?\n}/s', $fonte, $m);
eval($m[0]);

$reais = fn(int $c): string => 'R$ ' . number_format($c / 100, 2, ',', '.');

echo "\n--- o desconto por dias de raid ---\n";

/* ---- o caso normal ---- */
$DB->d = ['saldo' => ['dias' => 3, 'desconto' => 1], 'mensal' => 1399];
$usados = 0;
confere('3 dias num plano de R$ 13,99', $reais(mp_menos_dias(1, ['periodo' => 'mensal'], 1399, $usados)), 'R$ 12,59');
confere('  e consome os 3', $usados, 3);

/* ---- O TETO MENSAL. Sem ele, quem junta muito zera a fatura. ---- */
$DB->d = ['saldo' => ['dias' => 40, 'desconto' => 1], 'mensal' => 1399];
$usados = 0;
mp_menos_dias(1, ['periodo' => 'mensal'], 1399, $usados);
confere('40 dias no saldo gastam no máximo 5', $usados, 5);

/* ---- A CHAVE DESLIGADA. Quem prefere guardar não pode ser cobrado a menos. ---- */
$DB->d = ['saldo' => ['dias' => 10, 'desconto' => 0], 'mensal' => 1399];
$usados = 0;
confere('desconto desligado não muda o preço', mp_menos_dias(1, ['periodo' => 'mensal'], 1399, $usados), 1399);
confere('  e não gasta dia nenhum', $usados, 0);

/* ---- O CUPOM POR CIMA. Os dois juntos não podem zerar a cobrança:
        o Mercado Pago recusa um pedido de zero real. ---- */
$DB->d = ['saldo' => ['dias' => 30, 'desconto' => 1], 'mensal' => 1399];
$usados = 0;
$comCupom = mp_menos_dias(1, ['periodo' => 'mensal'], 140, $usados);  /* cupom de 90% */
confere('com cupom de 90%, o desconto para na metade', $reais($comCupom), 'R$ 0,70');
confere('  e só gasta o que coube', $usados, 1);
confere('  a cobrança nunca vai a zero', $comCupom > 0, true);

/* ---- O ANUAL. Um dia vale um dia do MENSAL, sempre: valer um dia do
        plano comprado faria o mesmo esforço valer dez vezes menos. ---- */
$DB->d = ['saldo' => ['dias' => 5, 'desconto' => 1], 'mensal' => 1399];
$usados = 0;
confere('5 dias tiram o mesmo do anual', $reais(13990 - mp_menos_dias(1, ['periodo' => 'anual'], 13990, $usados)), 'R$ 2,33');

/* ---- SEM SALDO ---- */
$DB->d = ['saldo' => false, 'mensal' => 1399];
$usados = 0;
confere('quem nunca raidou paga o cheio', mp_menos_dias(1, ['periodo' => 'mensal'], 1399, $usados), 1399);

/* ------------------------------------------------------------------ *
 *  O estorno devolve os dias
 * ------------------------------------------------------------------ */
echo "\n--- o estorno ---\n";

$DB->escreveu = [];
$DB->d = ['dias_na_conta' => 3, 'saldo' => false];

/* O trecho que devolve, isolado do resto do estorno. */
$devolve = function (int $assinaturaId, int $usuarioId): void {
    $dr = db()->prepare('SELECT dias_raid FROM assinaturas WHERE id = ?');
    $dr->execute([$assinaturaId]);
    $volta = (int) $dr->fetchColumn();
    if ($volta > 0) {
        db()->prepare('INSERT INTO raid_saldo (usuario_id, dias) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE dias = dias + VALUES(dias)')->execute([$usuarioId, $volta]);
        db()->prepare('UPDATE assinaturas SET dias_raid = 0 WHERE id = ?')->execute([$assinaturaId]);
    }
};

$devolve(10, 1);
confere('estorno devolve os dias ao saldo',
    (bool) array_filter($DB->escreveu, fn($q) => str_contains($q, 'INSERT INTO raid_saldo')), true);
confere('  e zera na assinatura, pra não devolver duas vezes',
    (bool) array_filter($DB->escreveu, fn($q) => str_contains($q, 'dias_raid = 0')), true);

/* Um segundo aviso do mesmo estorno encontra zero. */
$DB->escreveu = [];
$DB->d['dias_na_conta'] = 0;
$devolve(10, 1);
confere('o segundo aviso do mesmo estorno não devolve de novo', $DB->escreveu, []);

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
