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

$mp = mp_cfg();

/* ---------- a tabela de preços, sem chave ----------

   QUEM CHEGA NO SITE PRECISA VER QUANTO CUSTA.

   Isto ficava atrás do exige_painel() junto com todo o resto, e o efeito
   era o pior possível pra quem vai vender: o visitante não via preço
   nenhum. A tabela de preços é a informação mais pública que existe num
   produto pago — ela está na página de vendas de qualquer um.

   Vai SÓ a lista. Nada daqui olha quem está perguntando, porque ninguém
   está: sem plano da pessoa, sem validade, sem cupom, sem beta. Com a
   cobrança desligada ou em teste, nem a lista sai. */
if (isset($_GET['publico'])) {
    if (!$mp['ligado'] || $mp['modo'] === 'teste') {
        json_saida(['ligado' => false, 'planos' => []]);
    }

    $st = db()->query("SELECT slug, nome, preco_centavos, periodo FROM planos
                        WHERE preco_centavos > 0 ORDER BY preco_centavos");

    json_saida([
        'ligado' => true,
        'modo'   => $mp['modo'],
        'planos' => $st->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

/* ---------- a rede embaixo do webhook ----------

   ANTES DO exige_painel(): o cron não tem chave de painel nenhuma.

   POR QUE EXISTE: alguém pagou, o aviso do Mercado Pago não chegou, e o
   acesso só saiu porque a pessoa clicou em "já paguei e não liberou". Isso
   aconteceu de verdade, no primeiro pagamento.

   O botão continua valendo, mas ele exige que quem pagou saiba que o botão
   existe, esteja com paciência e o encontre. Quem não encontrar vai achar
   que pagou e foi roubado — e vai pedir estorno pelo banco, que tira o
   acesso, custa taxa e queima a conta no Mercado Pago.

   Entrega de webhook falha: é assim em qualquer provedor. Depender de uma
   entrega só pra liberar o que já foi pago é o erro de desenho, e não o
   webhook perdido. Aqui o servidor pergunta sozinho, de tempos em tempos,
   o que o Mercado Pago sabe das cobranças que ainda estão abertas.

   A pergunta é sempre PRO MERCADO PAGO. Nada aqui confia em banco nosso
   pra decidir quem pagou.

   O comando pronto, com o segredo já dentro, fica na tela "Os avisos do
   Mercado Pago". Não há segredo pra inventar: ele é sorteado. */
if (isset($_GET['cron'])) {
    $esperado = cron_segredo('cobranca');
    if ($esperado === '' || !hash_equals($esperado, (string) $_GET['cron'])) {
        /* 404 e não 403: quem chuta o segredo não merece saber que acertou
           o endereço. */
        json_saida(['erro' => 'Não encontrado.'], 404);
    }
    if (!$mp['ligado']) json_saida(['ok' => true, 'pulou' => 'cobrança desligada']);

    /* Só quem tem cobrança aberta. Varrer todo mundo seria uma consulta ao
       Mercado Pago por conta do site, de dez em dez minutos, pra nada. */
    $donos = [];
    try {
        $st = db()->query(
            "SELECT DISTINCT usuario_id FROM assinaturas
              WHERE status = 'pendente'
                AND criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)
              LIMIT 200"
        );
        $donos = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { /* sem tabela: não há o que conferir */ }

    $liberados = 0;
    $olhados = 0;
    foreach ($donos as $uid) {
        try {
            $r = mp_reconciliar((int) $uid);
            $olhados += (int) $r['pendentes'];
            $liberados += (int) $r['liberados'];
        } catch (Throwable $e) {
            /* Uma conta que deu erro não pode parar as outras: a próxima da
               fila pode ser justamente quem está sem o que pagou. */
            erro_anota($e);
        }
    }

    json_saida(['ok' => true, 'contas' => count($donos), 'cobrancas' => $olhados, 'liberados' => $liberados]);
}

$quem = exige_painel();
trava('checkout', 10, 300);

/* OS CUPONS QUE VALEM AGORA.

   Só os marcados como públicos na administração, cupom a cupom. O cupom
   de desconto maior, feito pra alguém específico, fica escondido e só vale
   pra quem tem o código. */
if (isset($_GET['cupons'])) {
    require_once __DIR__ . '/lib/cupons.php';

    $lista = [];
    $falhou = null;
    try {
        $q = db()->query(
            "SELECT codigo, descricao, tipo, valor, vale_ate, usos, usos_max
               FROM cupons
              WHERE publico = 1 AND ligado = 1
                AND (vale_ate IS NULL OR vale_ate > NOW())
                AND (usos_max IS NULL OR usos < usos_max)
              ORDER BY valor DESC LIMIT 8"
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $lista[] = [
                'codigo'    => $c['codigo'],
                'descricao' => $c['descricao'],
                'rotulo'    => $c['tipo'] === 'percentual'
                    ? ((int) $c['valor']) . '% de desconto'
                    : 'R$ ' . number_format(((int) $c['valor']) / 100, 2, ',', '.') . ' de desconto',
                'vale_ate'  => $c['vale_ate'],
                /* Quantos ainda restam, quando há limite: "faltam 3" faz
                   decidir agora, e é verdade. */
                'restam'    => $c['usos_max'] === null ? null
                    : max(0, (int) $c['usos_max'] - (int) $c['usos']),
            ];
        }
    } catch (Throwable $e) {
        $falhou = erro_publico($e);
    }

    /* LISTA VAZIA PRO ADMIN VEM COM O MOTIVO.

       Ela apareceu vazia duas vezes com cupons criados, e de fora não havia
       como saber o porquê. Pra quem administra, a resposta diz o que está
       tirando cada cupom da lista. */
    $motivo = null;
    if (!$lista) {
        $ad = db()->prepare('SELECT admin FROM usuarios WHERE id = ?');
        $ad->execute([(int) $quem['usuario_id']]);
        if ($ad->fetchColumn()) $motivo = cupons_motivo($falhou);
    }

    json_saida(['cupons' => $lista, 'motivo' => $motivo]);
}

/* CONFERIR UM CUPOM ANTES DE PAGAR.

   Existe pra que a pessoa veja o desconto na tela ANTES de sair do site.
   Digitar um código e só descobrir no checkout do Mercado Pago se ele valeu
   é o tipo de dúvida que faz desistir da compra. */
if (isset($_GET['cupom_teste'])) {
    require_once __DIR__ . '/lib/cupons.php';

    trava('cupom', 20, 300);

    $slug2 = (string) ($_GET['plano'] ?? 'pro');
    $pl = mp_plano_por_slug($slug2);
    if (!$pl) json_saida(['erro' => 'Esse plano não existe.'], 404);

    $v = cupom_valida((string) $_GET['cupom_teste']);
    if (empty($v['ok'])) json_saida(['ok' => false, 'erro' => $v['erro']]);

    $conta = cupom_aplica($v['cupom'], (int) $pl['preco_centavos']);
    json_saida([
        'ok'        => true,
        'codigo'    => cupom_limpa((string) $_GET['cupom_teste']),
        'rotulo'    => $conta['rotulo'],
        'de'        => $conta['de'],
        'por'       => $conta['por'],
        'descricao' => $v['cupom']['descricao'] ?? null,
    ]);
}

/* JÁ PAGUEI E NÃO LIBEROU.

   O caminho de escape pra quando o webhook se perde. Qualquer pessoa pode
   chamar pra si mesma — não é privilégio de admin, porque quem precisa disso
   é justamente o cliente comum no pior momento possível: acabou de pagar e
   não recebeu.

   Não confia em nada que venha do navegador: a única coisa que decide é o
   que o Mercado Pago responde sobre as cobranças DESTE usuário. */
if (isset($_GET['conferir'])) {
    if (!$mp['ligado']) json_saida(['erro' => 'A cobrança ainda não abriu.'], 503);

    trava('conferir', 6, 300);

    try {
        $r = mp_reconciliar((int) $quem['usuario_id']);
    } catch (Throwable $e) {
        json_saida(['erro' => erro_publico($e)], 502);
    }

    json_saida([
        'ok'        => true,
        'pendentes' => $r['pendentes'],
        'liberados' => $r['liberados'],
        'recado'    => $r['liberados'] > 0
            ? 'Achei o seu pagamento e liberei. Recarregue a página.'
            : ($r['pendentes'] > 0
                ? 'Encontrei cobrança aberta, mas o Mercado Pago ainda não confirmou o pagamento. '
                  . 'Se você pagou por Pix agora, espere um minuto e tente de novo.'
                : 'Não achei nenhuma cobrança sua dos últimos 30 dias esperando confirmação.'),
    ]);
}

/* DESLIGAR A RENOVAÇÃO.

   Só por POST: isto muda o estado da cobrança, e link que cancela assinatura
   sendo aberto num GET é assinatura cancelada por um preview de link.

   NÃO tira o acesso. O que já foi pago vale até o fim — a resposta devolve
   a data justamente pra tela poder dizer isso. */
if (isset($_GET['cancelar'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_saida(['erro' => 'Use POST pra cancelar.'], 405);
    }
    if (!$mp['ligado']) json_saida(['erro' => 'A cobrança ainda não abriu.'], 503);

    trava('cancelar', 6, 300);

    try {
        $r = mp_cancelar_assinatura((int) $quem['usuario_id']);
    } catch (Throwable $e) {
        json_saida(['erro' => erro_publico($e)], 502);
    }

    if (empty($r['ok'])) json_saida(['erro' => $r['erro']], 400);

    json_saida([
        'ok'     => true,
        'ate'    => $r['ate'],
        'recado' => $r['ate']
            ? 'Assinatura cancelada. O Pro continua até ' . date('d/m/Y', strtotime($r['ate'])) . '.'
            : 'Assinatura cancelada. Não vai mais ser cobrada.',
    ]);
}

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

    /* SE RENOVA SOZINHA, A TELA PRECISA DIZER.

       Cobrança que volta todo mês sem avisar é o que gera contestação no
       cartão. Quem assinou tem que ver, no painel, que renova e onde
       desligar — antes de ir procurar o banco. */
    $renova = 0;
    try {
        $rn = db()->prepare(
            "SELECT 1 FROM assinaturas
              WHERE usuario_id = ? AND renova = 1 AND assinatura_externa IS NOT NULL LIMIT 1"
        );
        $rn->execute([(int) $quem['usuario_id']]);
        $renova = $rn->fetchColumn() ? 1 : 0;
    } catch (Throwable $e) { /* sem o SQL 073: ninguém assina ainda */ }

    json_saida([
        'ligado'     => $mp['ligado'],
        'modo'       => $mp['modo'],
        'beta'       => $beta,
        'plano'      => $acesso['ativo'] ? $acesso['plano'] : 'gratis',
        'valido_ate' => $ate,
        'dias'       => $dias,
        'cortesia'   => !empty($acesso['cortesia']),
        'renova'     => (bool) $renova,
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

$codigo = (string) ($_GET['cupom'] ?? '');

/* O E-MAIL VEM NO CORPO, NUNCA NA URL.

   Endereço de e-mail é dado pessoal, e query string fica no registro do
   servidor, no histórico do navegador e no Referer que vaza pro próximo
   site. O corpo do POST não fica em nenhum dos três. */
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $corpo = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($corpo)) $email = (string) ($corpo['email'] ?? '');
}

/* ASSINATURA SEMPRE QUE O PLANO PERMITIR.

   Cobrança avulsa vende trinta dias e some. Quem comprou precisa lembrar
   de voltar, e a maior parte não volta — não por não gostar, por esquecer.
   Só o vitalício continua avulso: não existe renovar o que não vence. */
/* E SEMPRE DÁ PRA PAGAR UMA VEZ SÓ.

   Assinatura precisa de um meio de pagamento que aceite débito automático.
   Quem paga por Pix comum, ou não tem cartão, ou simplesmente não quer
   cobrança automática, não pode ficar sem poder comprar — essa venda
   existia antes e não pode sumir porque a assinatura chegou. A escolha é
   da pessoa e vem da tela. */
$assina = mp_pode_assinar($plano) && !isset($_GET['avulso']);

try {
    $r = $assina
        ? mp_criar_assinatura((int) $quem['usuario_id'], $plano, $codigo, $email)
        : mp_criar_cobranca((int) $quem['usuario_id'], $plano, $codigo);
} catch (Throwable $e) {
    json_saida(['erro' => erro_publico($e)], 502);
}

if (($r['url'] ?? '') === '') {
    json_saida(['erro' => 'O Mercado Pago não devolveu o link do checkout.'], 502);
}

json_saida([
    'url'    => $r['url'],
    'modo'   => $mp['modo'],
    'cupom'  => $r['cupom'],
    'assina' => $assina,
    /* Dias de desconto viram dias sem cobrança no começo, e a tela precisa
       do número pra explicar por que o primeiro débito não é hoje. */
    'gratis' => (int) ($r['gratis'] ?? 0),
    'plano'  => [
        'nome'     => $plano['nome'],
        'centavos' => (int) $plano['preco_centavos'],
        'cobrado'  => (int) $r['centavos'],
    ],
]);
