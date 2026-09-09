<?php
/**
 * Abre a cobrança e devolve o link do checkout HOSPEDADO do Mercado Pago.
 *
 * O cartão é digitado no domínio DELES, nunca no nosso. Nosso servidor não vê,
 * não trafega e não guarda número de cartão — e é isso que mantém a gente fora
 * do escopo pesado do PCI DSS. Nunca embutir campo de cartão em página nossa,
 * nem em iframe: isso já muda o questionário de SAQ A para SAQ A-EP.
 *
 * Só responde a quem já entrou com a Twitch: sem dono, um pagamento voltaria
 * e não haveria a quem liberar.
 *
 * Devolve JSON com a URL em vez de redirecionar. Redirecionar daqui tiraria
 * do painel a chance de mostrar o erro quando o Mercado Pago recusa.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/acesso.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/mercadopago.php';

cors();
$quem = exige_painel();
trava('checkout', 10, 300);

$mp = mp_cfg();

/* O DIAGNÓSTICO DA CREDENCIAL. Só admin, porque conta de qual conta do
   Mercado Pago o site está falando. */
if (isset($_GET['diagnostico'])) {
    $ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
    $ad->execute([(int) $quem['usuario_id']]);
    if (!$ad->fetchColumn()) json_saida(['erro' => 'Não encontrado.'], 404);

    json_saida(mp_diagnostico());
}

/* PERGUNTAR SEM COMPRAR.

   O painel precisa saber se existe cobrança antes de desenhar botão nenhum,
   e perguntar isso não pode criar uma cobrança. Por isso a consulta é um
   caminho separado, e ele responde 200 mesmo com a cobrança desligada. */
if (isset($_GET['estado'])) {
    $acesso = acesso_do_usuario((int) $quem['usuario_id']);

    $st = db()->query("SELECT slug, nome, preco_centavos, periodo FROM planos
                        WHERE preco_centavos > 0 ORDER BY preco_centavos");

    $st2 = db()->prepare("SELECT MAX(valido_ate) FROM assinaturas
                           WHERE usuario_id = ? AND status = 'ativa'");
    $st2->execute([(int) $quem['usuario_id']]);

    $ate = $st2->fetchColumn() ?: null;

    /* QUANTOS DIAS FALTAM, CONTADO NO SERVIDOR.

       O relógio da máquina de quem assiste pode estar torto, e "faltam 3
       dias" calculado lá viraria aviso na hora errada — cedo demais é
       barulho, tarde demais é a pessoa perdendo o Pro no meio da live sem
       nunca ter sido avisada. */
    $dias = null;
    if ($ate && $acesso['ativo']) {
        $dias = (int) floor((strtotime($ate) - time()) / 86400);
    }

    /* O painel precisa saber, senão ele mostraria "você está no grátis" pra
       quem tem tudo liberado — e a pessoa iria pagar por engano. */
    $beta = usuario_beta((int) $quem['usuario_id']);

    json_saida([
        'ligado'     => $mp['ligado'],
        'modo'       => $mp['modo'],
        'beta'       => $beta,
        'plano'      => $acesso['ativo'] ? $acesso['plano'] : 'gratis',
        'valido_ate' => $ate,
        'dias'       => $dias,
        'cortesia'   => !empty($acesso['cortesia']),
        'planos'     => $st->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

/* A CHAVE GERAL DA COBRANÇA.

   Enquanto estiver desligada, a estrutura inteira pode estar no ar sendo
   testada sem que exista jeito de alguém ser cobrado por acidente. Ligar é
   uma linha no config.php, e é a ÚLTIMA coisa a fazer — depois do teste de
   ponta a ponta passar. */
if (!$mp['ligado']) {
    json_saida([
        'erro'   => 'A cobrança ainda não abriu. Por enquanto está tudo liberado.',
        'ligado' => false,
    ], 503);
}

$slug  = (string) ($_GET['plano'] ?? 'pro');
$plano = mp_plano_por_slug($slug);

if (!$plano || (int) $plano['preco_centavos'] <= 0) {
    json_saida(['erro' => 'Esse plano não existe ou não é pago.'], 404);
}

try {
    $r = mp_criar_cobranca((int) $quem['usuario_id'], $plano);
} catch (Throwable $e) {
    json_saida(['erro' => $e->getMessage()], 502);
}

if (($r['url'] ?? '') === '') {
    json_saida(['erro' => 'O Mercado Pago não devolveu o link do checkout.'], 502);
}

json_saida([
    'url'   => $r['url'],
    'modo'  => $mp['modo'],
    'plano' => ['nome' => $plano['nome'], 'centavos' => (int) $plano['preco_centavos']],
]);
