<?php
/**
 * O TTS: o catálogo de vozes e a fila.
 *
 * AS VOZES SÃO JEITOS DE FALAR, NÃO PESSOAS.
 *
 * Cada uma é a voz do sistema com outro tom e outra velocidade. Não há
 * clonagem aqui, e é escolha, não limitação: voz de personagem tem dono e
 * dublador, e aceitar áudio de gente pra clonar abre a porta pra clonarem
 * a voz de qualquer um. Isto dá quase toda a graça sem nada disso.
 *
 * PRA SOMAR UMA VOZ: uma linha aqui e a mesma linha no docs/tts.js. O
 * conferir.js compara as duas listas e reclama se divergirem — é assim que
 * elas não se separam com o tempo.
 *
 *   tom  0.1 a 2.0 — grave embaixo, agudo em cima
 *   vel  0.5 a 2.0 — devagar embaixo, rápido em cima
 */
const TTS_VOZES = [
    'padrao', 'grave', 'agudo', 'crianca', 'narrador',
    'apressado', 'arrastado', 'gigante', 'sussurro',
];

/** Quanto tempo uma mensagem esperada na fila ainda vale. */
const TTS_VALE_SEG = 180;

/** A config deste canal, com os padrões de quem nunca configurou. */
function tts_config(int $uid): array
{
    $padrao = ['ligado' => 0, 'voz' => 'padrao', 'vozes' => '', 'aleatorio' => 0,
               'prefixo' => 0, 'max_letras' => 200, 'bloqueadas' => '', 'premio_id' => '',
               'calar_em' => null];
    try {
        $st = db()->prepare('SELECT * FROM tts_config WHERE usuario_id = ?');
        $st->execute([$uid]);
        $l = $st->fetch();
        return $l ? array_merge($padrao, $l) : $padrao;
    } catch (Throwable $e) {
        return $padrao;              /* sem o SQL 062, ninguém tem TTS */
    }
}

/**
 * Limpa o que um espectador digitou até virar algo que dá pra falar.
 *
 * ISTO É A PEÇA MAIS IMPORTANTE DO RECURSO. O texto veio de estranho, vai
 * sair pela boca do canal, e é o streamer que leva o banimento. Cada
 * regra aqui existe porque sem ela alguém faz o bot dizer o que não deve.
 */
function tts_limpa(string $cru, array $cfg): string
{
    $t = trim($cru);

    /* Endereço não se lê. Além de propaganda, "ponto com" falado é o jeito
       mais fácil de mandar a audiência de alguém pra fora. */
    $t = preg_replace('~\b(?:https?://|www\.)\S+~iu', ' ', $t);
    $t = preg_replace('~\b[\w.-]+\.(?:com|net|org|br|tv|gg|io|me|ly|xyz)\b\S*~iu', ' ', $t);

    /* Repetição vira ruído de minuto: "aaaaaaaa" fala igual a "aaa". */
    $t = preg_replace('/(.)\1{3,}/u', '$1$1$1', $t);

    /* Só o que se fala. Isto tira de uma vez os caracteres invisíveis, o
       texto de direita pra esquerda e os blocos que viram ruído. */
    $t = preg_replace('/[^\p{L}\p{N}\s.,!?;:\'"()\-]/u', ' ', $t);
    $t = trim(preg_replace('/\s+/u', ' ', $t));

    foreach (preg_split('/[\s,]+/u', mb_strtolower((string) ($cfg['bloqueadas'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $palavra) {
        if (mb_strlen($palavra) < 2) continue;
        $t = preg_replace('/' . preg_quote($palavra, '/') . '/iu', ' ', $t);
    }

    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return mb_substr($t, 0, max(20, min(400, (int) ($cfg['max_letras'] ?? 200))));
}

/** As vozes que valem pra este canal, na ordem em que ele as pôs. */
function tts_vozes_do_canal(array $cfg): array
{
    $lista = preg_split('/[\s,]+/', (string) ($cfg['vozes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $lista = array_values(array_intersect($lista, TTS_VOZES));
    if (!$lista) $lista = [in_array($cfg['voz'] ?? '', TTS_VOZES, true) ? $cfg['voz'] : 'padrao'];
    return $lista;
}

/**
 * Põe uma fala na fila. Devolve o motivo quando recusa, ou '' quando entra.
 *
 * O teto por pessoa e por canal fica aqui, e não na tela: a tela não é o
 * caminho, o resgate de pontos é.
 */
function tts_enfileira(int $uid, string $texto, string $quem, string $vozPedida = ''): string
{
    $cfg = tts_config($uid);
    if (!(int) $cfg['ligado']) return 'desligado';

    $limpo = tts_limpa($texto, $cfg);
    if ($limpo === '') return 'vazio';

    /* Um canal não vira megafone, e uma pessoa não ocupa a fila. */
    if (!limite_ok('tts:' . $uid, 20, 60)) return 'canal-cheio';
    if ($quem !== '' && !limite_ok('ttsp:' . $uid . ':' . $quem, 3, 60)) return 'pessoa-cheia';

    $vozes = tts_vozes_do_canal($cfg);
    $voz = in_array($vozPedida, $vozes, true)
        ? $vozPedida
        : ((int) $cfg['aleatorio'] ? $vozes[random_int(0, count($vozes) - 1)] : $vozes[0]);

    try {
        db()->prepare('INSERT INTO tts_fila (usuario_id, texto, voz, quem) VALUES (?, ?, ?, ?)')
            ->execute([$uid, $limpo, $voz, mb_substr($quem, 0, 40)]);
    } catch (Throwable $e) {
        return 'sem-tabela';
    }
    return '';
}

/**
 * O que ainda não foi falado, e a faxina do que perdeu a hora.
 *
 * Marca como falado ao entregar: entregar duas vezes seria a mesma frase
 * duas vezes na live, e isso é pior do que perder uma.
 */
function tts_pega(int $uid, int $quantas = 5): array
{
    try {
        $st = db()->prepare(
            'SELECT id, texto, voz, quem FROM tts_fila
              WHERE usuario_id = ? AND falado_em IS NULL
                AND criado_em > DATE_SUB(NOW(), INTERVAL ' . TTS_VALE_SEG . ' SECOND)
              ORDER BY id LIMIT ' . max(1, min(10, $quantas))
        );
        $st->execute([$uid]);
        $linhas = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    /* A FRASE INTEIRA VEM MONTADA DAQUI.

       Ela já foi montada no overlay, e isso dependia da fonte do OBS
       estar com o código do dia — o OBS guarda o arquivo em cache e a
       pessoa não tem como saber qual versão está rodando. Montando aqui,
       funciona na hora, com a fonte que já estiver aberta.

       A frase fixa entre o nome e a mensagem é o que impede alguém de se
       passar por outra pessoa: sem ela, uma mensagem que começa com
       "fulano disse que" sairia colada no nome de quem resgatou.

       O 'texto' continua indo limpo, porque é ele que aparece escrito na
       tela — lá o nome já tem linha própria e repetir seria ruído. */
    foreach ($linhas as $i => $l) {
        $linhas[$i]['dizer'] = $l['quem'] !== ''
            ? $l['quem'] . ' resgatou uma mensagem de voz e falou: ' . $l['texto']
            : $l['texto'];
    }

    if ($linhas) {
        $ids = array_column($linhas, 'id');
        $em = implode(',', array_map('intval', $ids));
        try {
            db()->exec('UPDATE tts_fila SET falado_em = NOW() WHERE id IN (' . $em . ')');
        } catch (Throwable $e) { /* falar é mais importante que marcar */ }
    }

    /* Faxina de vez em quando, e não a cada leitura: o overlay de TTS
       pergunta de 3 em 3 segundos, então "toda leitura" seriam vinte
       DELETEs por minuto por fonte aberta, pra apagar o que já não há. */
    if (random_int(1, 40) === 1) {
        try {
            db()->prepare('DELETE FROM tts_fila WHERE usuario_id = ? AND criado_em < DATE_SUB(NOW(), INTERVAL 1 HOUR)')
                ->execute([$uid]);
        } catch (Throwable $e) { /* a fila já saiu */ }
    }

    return $linhas;
}
