<?php
/**
 * Modo B: o overlay no OBS pergunta "mudou?" a cada 10 s com um link curto e fixo.
 * A chave pública aparece em print, em VOD e em tutorial — por isso ela SÓ LÊ.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/assinatura.php';
require_once __DIR__ . '/lib/contagem.php';
require_once __DIR__ . '/lib/eventos.php';
require_once __DIR__ . '/lib/tts.php';
require_once __DIR__ . '/lib/spotify.php';

header('Access-Control-Allow-Origin: *');   // o overlay roda dentro do OBS

/* SEM ESTA LINHA, OS DOIS CABEÇALHOS ABAIXO SÃO INVISÍVEIS PRO OVERLAY.
   Resposta de outra origem só entrega ao JavaScript os cabeçalhos da lista
   curta do CORS, e nem Date nem ETag estão nela. Faltando o Expose, o
   r.headers.get('Date') do overlay voltava null e a sincronia de relógio do
   subathon nunca rodou uma vez sequer: o cronômetro seguia o relógio da
   máquina de quem transmite — justamente o que ele tentava não fazer. */
header('Access-Control-Expose-Headers: ETag, Date');

/* POR QUE NÃO TEM LIMITE DE TENTATIVAS AQUI.

   Este é o único endereço que qualquer um alcança sem chave de painel, então
   a pergunta é natural. Duas respostas:

   Adivinhar a chave não é o risco: são 72 bits sorteados, e não existe
   máquina que percorra isso.

   Contra enxurrada de pedidos, um limite aqui atrapalharia mais do que
   ajuda. O limite_ok() custa DUAS idas ao banco, e este é o endereço mais
   quente do sistema — cada overlay aberto pergunta a cada 15 segundos. Eu
   dobraria o trabalho de banco do caminho mais usado pra me defender de algo
   que, quando acontece, já chegou no PHP e no banco de qualquer jeito.

   Enxurrada se barra ANTES do PHP: Cloudflare na frente do api.zocahop.com,
   que é de graça e resolve de verdade. */

$chave = $_GET['k'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $chave)) {
    json_saida(['erro' => 'Link inválido. Gere um novo no painel.'], 400);
}

$st = db()->prepare(
    'SELECT id, tipo, config, atualizado_em, usuario_id FROM perfis WHERE chave_publica = ? LIMIT 1'
);
$st->execute([$chave]);
$perfil = $st->fetch();

if (!$perfil) {
    json_saida(['erro' => 'Link inválido. Gere um novo no painel.'], 404);
}

// Quem decide o que está liberado é o servidor. Sempre.
$acesso   = acesso_do_usuario((int) $perfil['usuario_id']);
$recursos = recursos_do_usuario((int) $perfil['usuario_id'], $acesso['ativo'] ? $acesso['plano'] : 'gratis');

/* ALÉM DO TETO DO PLANO, O OVERLAY PARA DE DESENHAR.
 *
 * Quem tinha trinta overlays no Pro e deixou de pagar volta a ter direito a
 * oito. Os outros vinte e dois não são apagados — eles ficam guardados,
 * param de aparecer, e voltam sozinhos quando a assinatura voltar.
 *
 * QUAIS oito continuam: os mais antigos. Precisa ser uma regra que não
 * depende de escolha, senão o site teria que perguntar isso justamente na
 * hora em que a pessoa está sem acesso — e ordem de criação é a única que
 * dá o mesmo resultado toda vez.
 *
 * O overlay bloqueado devolve tela VAZIA, e não um aviso pedindo pagamento:
 * isso aqui está dentro de uma transmissão ao vivo, e ninguém merece um
 * cartaz de cobrança na frente da audiência. Quem precisa saber é o dono, e
 * o painel diz pra ele em vermelho. */
$teto = (int) ($recursos['perfis_max'] ?? 0);

$pos = db()->prepare('SELECT COUNT(*) FROM perfis WHERE usuario_id = ? AND id < ?');
$pos->execute([(int) $perfil['usuario_id'], (int) $perfil['id']]);
$bloqueado = $teto > 0 && ((int) $pos->fetchColumn()) >= $teto;

if ($bloqueado) {
    header('Cache-Control: no-cache, must-revalidate');
    json_saida([
        'tipo'       => $perfil['tipo'],
        'config'     => null,
        'bloqueado'  => true,
        'recursos'   => $recursos,
    ]);
}

$config = json_decode($perfil['config'], true) ?: [];

/* META AUTOMÁTICA.
   O número não é digitado: vem da Twitch. O overlay nunca fala com ela — ele
   pergunta aqui, e aqui a resposta fica guardada por um minuto, senão cada
   batida do OBS viraria uma chamada.
   Se a Twitch não responder, contagem() devolve o último número conhecido em
   vez de zero: uma meta que despenca no meio da live é pior que uma parada. */
$fonte = (string) ($config['fonte'] ?? 'manual');
/* AS METAS DO SUBATHON NÃO PODEM DEPENDER DE UM OVERLAY DE META ABERTO.

   A conferência de "bateu o alvo" morava só no caminho do overlay de meta.
   Quem tivesse subathon e nenhuma meta na tela nunca via o tempo somar — e
   não teria como desconfiar do motivo. Aqui o próprio subathon confere as
   suas, e só as que ele configurou: sem alvo, nenhuma chamada é feita. */
if ($perfil['tipo'] === 'subathon') {
    try {
        $sub = db()->prepare(
            'SELECT seguidores_alvo, subs_alvo FROM subathon WHERE usuario_id = ? AND ligado = 1'
        );
        $sub->execute([(int) $perfil['usuario_id']]);
        if ($alvos = $sub->fetch()) {
            foreach (['seguidores', 'subs'] as $f) {
                if (empty($alvos[$f . '_alvo'])) continue;
                /* O contagem() já dispara o meta_bateu por dentro, e ele
                   guarda a resposta por um minuto — então isto não vira uma
                   chamada à Twitch a cada batida do OBS. */
                contagem((int) $perfil['usuario_id'], $f);
            }
        }
    } catch (Throwable $e) { /* meta é extra: não derruba o subathon */ }
}

if ($perfil['tipo'] === 'meta' && $fonte !== 'manual') {
    /* De qual plataforma. Sem isso a meta de um canal do Kick perguntaria à
       Twitch e mostraria o número errado sem nunca reclamar. */
    $plat = (string) ($config['plataforma'] ?? 'twitch');
    $auto = contagem((int) $perfil['usuario_id'], $fonte, 60, $plat);
    if ($auto !== null) {
        $config['atual'] = $auto;
        /* A meta de viewers vive de saber quantos estao assistindo AGORA.
           Aproveito a consulta que ja foi feita em vez de pedir de novo. */
        if ($fonte === 'viewers' && $plat === 'twitch') viewers_meta((int) $perfil['usuario_id'], $auto);
    }
}

/* O FEED.

   Os eventos vao no corpo da resposta, e nao dentro da config: config e o
   que a PESSOA escolheu, e isso aqui muda sozinho. Misturar os dois faria
   cada sub novo parecer uma edicao do overlay. */
/* A MUSICA vai no corpo, como os eventos: ela muda sozinha, e config e o que
   a PESSOA escolheu. Junto, cada troca de faixa pareceria uma edicao. */
$musica = null;
if ($perfil['tipo'] === 'musica') {
    /* De onde vem a música. Last.fm é o padrão porque atende qualquer pessoa;
       o Spotify atende cinco contas enquanto o app estiver em modo de
       desenvolvimento — mas só ele faz !pular, !fila e !like. */
    $fonteMus = (string) ($config['mfonte'] ?? 'lastfm');
    try {
        if ($fonteMus === 'spotify') {
            $musica = sp_tocando((int) $perfil['usuario_id']);
        } else {
            require_once __DIR__ . '/lib/lastfm.php';
            $musica = lf_tocando((int) $perfil['usuario_id']);
        }
    } catch (Throwable $e) { $musica = null; }
}

$eventos = null;
if ($perfil['tipo'] === 'feed') {
    $eventos = evento_recentes((int) $perfil['usuario_id'], max(1, min(30, (int) ($config['cmax'] ?? 8))));
} elseif ($perfil['tipo'] === 'alerta') {
    /* A CHAVE GERAL DOS ALERTAS.

       Desligada, a fonte não recebe nem o evento — e não há como ela
       decidir errado. Marcar como visto mesmo assim é de propósito: sair
       do silêncio não pode despejar de uma vez tudo o que aconteceu
       enquanto ele estava desligado. */
    $ligados = true;
    try {
        $ch = db()->prepare('SELECT alertas_ligados FROM usuarios WHERE id = ?');
        $ch->execute([(int) $perfil['usuario_id']]);
        $v = $ch->fetchColumn();
        $ligados = $v === false ? true : (bool) $v;
    } catch (Throwable $e) { /* sem o SQL 065: os alertas seguem ligados */ }

    /* Poucos: o alerta mostra um de cada vez e a consulta é de 15 em 15
       segundos. Mais que isso viraria fila do dia inteiro se a fonte ficasse
       um tempo fora do ar. */
    $eventos = evento_recentes((int) $perfil['usuario_id'], 12);
    if (!$ligados) $eventos = [];
}

/* A CONTAGEM REGRESSIVA.

   Quanto falta é contado AQUI, e não na máquina de quem assiste: relógio
   torto do espectador daria uma contagem que discorda da do streamer, e
   duas contagens diferentes na mesma live é pior que nenhuma. */
$contagem = null;
if ($perfil['tipo'] === 'relogio' && !empty($config['crligada'])) {
    try {
        $cr = db()->prepare('SELECT alvo, fim_texto FROM contagem_regressiva WHERE usuario_id = ?');
        $cr->execute([(int) $perfil['usuario_id']]);
        if ($l = $cr->fetch()) {
            $contagem = [
                'faltam' => $l['alvo'] ? max(0, strtotime((string) $l['alvo']) - time()) : null,
                'texto'  => (string) $l['fim_texto'],
            ];
        }
    } catch (Throwable $e) { /* sem o SQL 066: relógio normal */ }
}

/* A FILA DO TTS.

   Pega aqui, e não num endereço próprio: a fonte já bate neste a cada
   poucos segundos, e um segundo canal seria o dobro de requisição pelo
   mesmo dado. O tts_pega() já marca como falado ao entregar — o que sai
   daqui não sai duas vezes.

   SÓ O TIPO TTS. Um overlay de relógio não tem por que ler fila de fala,
   e cada leitura dessas é um UPDATE no banco. */
$fala = null;
$calar = null;
if ($perfil['tipo'] === 'tts') {
    $fala = tts_pega((int) $perfil['usuario_id']);
    /* O instante do último "calar o bot". Vai sempre, e não só quando é
       novo: a fonte é quem sabe se já obedeceu esse — ela pode ter nascido
       agora, ou ter ficado um tempo fora do ar. */
    $calar = tts_config((int) $perfil['usuario_id'])['calar_em'] ?: null;
}

/* O ENDEREÇO DO SOM PRÓPRIO.

   Vai montado daqui porque quem sabe onde a API mora é o servidor, não a
   fonte dentro do OBS. E vai só o endereço de tocar, com a chave PÚBLICA do
   overlay: a fonte não tem a chave do painel, e não deveria ter. */
$som = null;
if ($perfil['tipo'] === 'alerta' && !empty($config['asomid'])) {
    $som = api_base()
         . '/som.php?a=tocar&k=' . rawurlencode($chave)
         . '&id=' . (int) $config['asomid'];
}

/* OS SONS AMARRADOS A PRÊMIO, cada um com o endereço pronto.

   O alerta tem UM som pra tudo. Quando o chat resgata um prêmio que tem som
   próprio, o evento traz o id — e a fonte precisa do endereço daquele id
   sem saber onde a API mora nem ter a chave do painel.

   São poucos e só os amarrados: mandar a biblioteca inteira faria a fonte
   baixar áudio que nunca vai tocar. */
$sonsPremio = null;
if ($perfil['tipo'] === 'alerta') {
    try {
        $st3 = db()->prepare(
            'SELECT DISTINCT som_id FROM sons_premio WHERE usuario_id = ? LIMIT 30'
        );
        $st3->execute([(int) $perfil['usuario_id']]);
        foreach ($st3->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $sonsPremio[(string) (int) $sid] = api_base()
                . '/som.php?a=tocar&k=' . rawurlencode($chave) . '&id=' . (int) $sid;
        }
    } catch (Throwable $e) { /* sem o SQL 075: ninguém amarrou som nenhum */ }
}

/* O ÍCONE DE CADA TRECHO, COM O ENDEREÇO PRONTO.

   Montado aqui pelo mesmo motivo do som: quem sabe onde a API mora é o
   servidor, e a fonte dentro do OBS só tem a chave pública. */
if ($perfil['tipo'] === 'speedrun' && !empty($config['sptre']) && is_array($config['sptre'])) {
    foreach ($config['sptre'] as $k => $tr) {
        if (empty($tr['i'])) continue;
        $config['sptre'][$k]['iu'] = api_base() . '/imagens.php?a=ver&k=' . rawurlencode($chave)
                                   . '&id=' . (int) $tr['i'];
    }
}

/* ETag: quase toda resposta vira um 304 de poucos bytes. O polling sai de graça.
   O número automático entra na conta — sem ele o 304 devolveria o valor velho
   pra sempre e a meta ficaria congelada, justo a que deveria se mexer sozinha. */
/* A FALA ENTRA NA CONTA, E É OBRIGATÓRIO.

   Sem ela, uma mensagem nova não mudaria o ETag: a resposta viraria 304,
   a fonte não receberia nada, e o TTS ficaria mudo pra sempre sem dar
   erro nenhum. É o mesmo motivo do número automático logo acima. */
$etag = '"' . md5($perfil['atualizado_em'] . '|' . json_encode($recursos)
                  . '|' . (string) ($config['atual'] ?? '')
                  . '|' . ($eventos === null ? '' : md5(json_encode($eventos)))
                  . '|' . ($musica === null ? '' : md5(json_encode($musica)))
                  . '|' . ($fala ? md5(json_encode($fala)) : '')
                  . '|' . (string) $calar
                  /* Sem isto o 304 devolveria sempre o mesmo "faltam", e a
                     contagem ficaria parada no número da primeira leitura. */
                  . '|' . ($contagem === null ? '' : (string) $contagem['faltam'])
                  . '|' . (string) $som) . '"';
header('ETag: ' . $etag);
header('Cache-Control: no-cache, must-revalidate');

/* O ETAG VOLTA DIFERENTE DO QUE SAIU.

   Medido na producao: o PHP manda ETag: "abc" e a resposta chega ao
   navegador como ETag: W/"abc". Quem poe o W/ e a camada da hospedagem, que
   comprime a resposta — comprimida, ela nao e byte a byte a mesma coisa, e
   o padrao manda enfraquecer o ETag nesse caso.

   O navegador devolve no If-None-Match exatamente o que recebeu, com o W/.
   A comparacao literal com $etag entao NUNCA batia, e o 304 nunca aconteceu:
   toda consulta de todo overlay, a cada 15 segundos, vinha com o corpo
   inteiro. Comparar sem o prefixo conserta isso.

   A lista tambem e aceita porque o cabecalho pode trazer varios valores
   separados por virgula, e "*" quer dizer "qualquer um serve". */
$recebido = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($recebido !== '') {
    $limpo = static function (string $v): string {
        $v = trim($v);
        if (stripos($v, 'W/') === 0) $v = substr($v, 2);
        return trim($v);
    };
    $alvo = $limpo($etag);
    foreach (explode(',', $recebido) as $um) {
        $um = $limpo($um);
        if ($um === '*' || $um === $alvo) {
            http_response_code(304);
            exit;
        }
    }
}

json_saida([
    'tipo'     => $perfil['tipo'],
    'config'   => $config,
    'eventos'  => $eventos,
    'fala'     => $fala,
    'calar'    => $calar,
    'contagem' => $contagem,
    'musica'   => $musica,
    'som'      => $som,
    'sons'     => $sonsPremio,
    'recursos' => $recursos,
]);
