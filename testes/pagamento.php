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
 *  O desconto da ASSINATURA anda em dias, e não em reais
 * ------------------------------------------------------------------ */
echo "\n--- os dias grátis da assinatura ---\n";

/* O que mp_dias_gratis() usa e mora em outros arquivos. O cupom de mentira
   é percentual porque é o caso que converte errado com mais facilidade. */
const MP_DIAS = ['mensal' => 30, 'anual' => 365];

$CUPOM = null;
function cupom_limpa(string $c): string { return strtoupper(trim($c)); }
function cupom_valida(string $c): array
{
    global $CUPOM;
    return $CUPOM === null ? ['ok' => false] : ['ok' => true, 'cupom' => $CUPOM];
}
function cupom_aplica(array $c, int $centavos): array
{
    return ['por' => (int) round($centavos * (100 - $c['pct']) / 100)];
}

preg_match('/function mp_dias_gratis.*?\n}/s', $fonte, $m2);
eval($m2[0]);
preg_match('/function mp_pode_assinar.*?\n}/s', $fonte, $m3);
eval('const MP_RECORRENCIA = ' . var_export(['mensal' => [1, 'months'], 'anual' => [12, 'months']], true) . ';' . $m3[0]);

$MENSAL = ['periodo' => 'mensal', 'preco_centavos' => 1399];
$ANUAL  = ['periodo' => 'anual',  'preco_centavos' => 13990];

/* ---- o caso normal: dia de raid vira dia grátis, sem conversão ---- */
$CUPOM = null;
$DB->d = ['saldo' => ['dias' => 3, 'desconto' => 1]];
$dr = 0; $cp = '';
confere('3 dias de raid viram 3 dias grátis', mp_dias_gratis(1, $MENSAL, '', $dr, $cp), 3);
confere('  e consome os 3', $dr, 3);

/* ---- O TETO. Metade do período, igual ao caminho avulso. ---- */
$DB->d = ['saldo' => ['dias' => 40, 'desconto' => 1]];
$dr = 0;
confere('40 dias no saldo gastam no máximo 5', mp_dias_gratis(1, $MENSAL, '', $dr, $cp), 5);
confere('  e gasta 5', $dr, 5);

/* ---- A CHAVE DESLIGADA ---- */
$DB->d = ['saldo' => ['dias' => 10, 'desconto' => 0]];
$dr = 0;
confere('desconto desligado não dá dia nenhum', mp_dias_gratis(1, $MENSAL, '', $dr, $cp), 0);
confere('  e não gasta dia nenhum', $dr, 0);

/* ---- O CUPOM VIRA DIAS.

       É AQUI QUE ASSINATURA DIFERE DE COMPRA AVULSA: baixar o valor mensal
       daria o desconto do cupom TODO MÊS, pra sempre. Virando dias, ele
       acontece uma vez, que é o que um cupom é. ---- */
$CUPOM = ['pct' => 50];
$DB->d = ['saldo' => false];
$dr = 0; $cp = '';
confere('cupom de 50% no mensal vira metade do mês', mp_dias_gratis(1, $MENSAL, 'META50', $dr, $cp), 14);
confere('  e guarda o código do cupom', $cp, 'META50');

/* ---- CUPOM E RAID JUNTOS param no teto, e o que sobra fica no saldo ---- */
$CUPOM = ['pct' => 50];
$DB->d = ['saldo' => ['dias' => 5, 'desconto' => 1]];
$dr = 0;
confere('cupom + raid param na metade do período', mp_dias_gratis(1, $MENSAL, 'META50', $dr, $cp), 15);
confere('  e só gasta o dia de raid que coube', $dr, 1);

/* ---- O ANUAL: o dia de raid continua valendo um dia ---- */
$CUPOM = null;
$DB->d = ['saldo' => ['dias' => 5, 'desconto' => 1]];
$dr = 0;
confere('5 dias de raid no anual valem 5 dias', mp_dias_gratis(1, $ANUAL, '', $dr, $cp), 5);

/* ---- QUEM PODE SER ASSINATURA ---- */
confere('mensal é assinatura', mp_pode_assinar($MENSAL), true);
confere('anual é assinatura', mp_pode_assinar($ANUAL), true);
confere('vitalício não renova', mp_pode_assinar(['periodo' => 'vitalicio']), false);

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

/* ------------------------------------------------------------------ *
 *  A assinatura do webhook do Mercado Pago
 * ------------------------------------------------------------------ */
echo "\n--- a conferência da assinatura do webhook ---\n";

/* POR QUE ISTO EXISTE: o primeiro pagamento de assinatura real entrou na
   conta e o site recusou o aviso. O molde do HMAC levava 'request-id:;'
   quando o cabeçalho não vinha, e levava o id do corpo no lugar do id da
   URL. Aviso de pagamento traz os dois e passava; aviso de assinatura não
   traz, e era recusado — com o dinheiro já recebido.

   É um erro que não aparece em lugar nenhum até alguém pagar. */

const SEGREDO_FALSO = 'segredo-de-mentira-pro-ensaio';
function cfg(): array { return ['mercadopago' => ['webhook_secret' => SEGREDO_FALSO]]; }

preg_match('/function mp_webhook_valido.*?\n}/s', file_get_contents(__DIR__ . '/../api/lib/seguranca.php'), $mw);
eval($mw[0]);

/* O molde escrito do jeito que a documentação deles manda, pra comparar
   contra o que a função monta por dentro. */
$assina = function (string $molde) {
    return hash_hmac('sha256', $molde, SEGREDO_FALSO);
};
$cabecalho = fn(int $ts, string $v1) => 'ts=' . $ts . ',v1=' . $v1;

$ts = time() * 1000;   /* eles mandam em milissegundos */

/* ---- aviso de pagamento: traz id na URL e request-id ---- */
$molde = 'id:123456;request-id:abc-def;ts:' . $ts . ';';
confere('aviso completo é aceito',
    mp_webhook_valido($cabecalho($ts, $assina($molde)), 'abc-def', '123456'), true);

/* ---- AVISO DE ASSINATURA: sem request-id.

       O pedaço tem que sair do molde, e não entrar vazio. Era exatamente
       este caso que estava sendo recusado. ---- */
$molde = 'id:2c93808;ts:' . $ts . ';';
confere('sem request-id, o pedaço sai do molde',
    mp_webhook_valido($cabecalho($ts, $assina($molde)), '', '2c93808'), true);

/* ---- sem id na URL: mesma regra ---- */
$molde = 'request-id:abc-def;ts:' . $ts . ';';
confere('sem id na URL, o pedaço sai do molde',
    mp_webhook_valido($cabecalho($ts, $assina($molde)), 'abc-def', ''), true);

/* ---- o id alfanumérico vai em minúsculo ---- */
$molde = 'id:ord01jq4s;request-id:r1;ts:' . $ts . ';';
confere('id alfanumérico é comparado em minúsculo',
    mp_webhook_valido($cabecalho($ts, $assina($molde)), 'r1', 'ORD01JQ4S'), true);

/* ---- E O PORTÃO CONTINUA FECHADO PRO RESTO ---- */
confere('assinatura de outro segredo é recusada',
    mp_webhook_valido($cabecalho($ts, hash_hmac('sha256', 'id:1;ts:' . $ts . ';', 'outro')), '', '1'), false);

confere('o molde velho, com request-id vazio, não passa mais',
    mp_webhook_valido($cabecalho($ts, $assina('id:1;request-id:;ts:' . $ts . ';')), '', '1'), false);

confere('cabeçalho sem v1 é recusado', mp_webhook_valido('ts=' . $ts, '', '1'), false);
confere('cabeçalho vazio é recusado', mp_webhook_valido('', '', '1'), false);

/* ---- AVISO VELHO É AVISO REENVIADO. A janela de frescor impede que
        alguém guarde uma notificação boa e reenvie depois. ---- */
$velho = (time() - 3600) * 1000;
confere('aviso de uma hora atrás é recusado',
    mp_webhook_valido($cabecalho($velho, $assina('id:1;ts:' . $velho . ';')), '', '1'), false);

/* ---- O ts em SEGUNDOS também vale: eles já mandaram dos dois jeitos. ---- */
$seg = time();
confere('ts em segundos também é aceito',
    mp_webhook_valido($cabecalho($seg, $assina('id:1;ts:' . $seg . ';')), '', '1'), true);

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "tudo certo\n");
exit($falhas ? 1 : 0);
