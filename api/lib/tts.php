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
               'calar_em' => null, 'segurar' => 0];
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

    /* Um canal não vira megafone, e uma pessoa não ocupa a fila.

       Os números de antes (20 no canal, 3 por pessoa por minuto) eram
       apertados demais: quem estava testando batia no teto em três
       resgates e via o TTS "parar de funcionar" sem nada explicando.
       Quem paga com pontos do canal já tem o freio do próprio preço do
       prêmio — isto aqui é só contra enxurrada. */
    if (!limite_ok('tts:' . $uid, 40, 60)) return 'canal-cheio';
    if ($quem !== '' && !limite_ok('ttsp:' . $uid . ':' . $quem, 10, 60)) return 'pessoa-cheia';

    $vozes = tts_vozes_do_canal($cfg);
    $voz = in_array($vozPedida, $vozes, true)
        ? $vozPedida
        : ((int) $cfg['aleatorio'] ? $vozes[random_int(0, count($vozes) - 1)] : $vozes[0]);

    /* COM O MODO SEGURAR, A MENSAGEM NASCE ESPERANDO.

       O "pular a fala" só existe depois que ela começou — e o estrago de
       uma frase lida em voz alta acontece na primeira sílaba. Segurando,
       quem transmite lê antes e decide; quem não ligar o modo não muda
       nada, porque a coluna nasce aprovada. */
    $aprovado = (int) ($cfg['segurar'] ?? 0) ? 0 : 1;

    try {
        try {
            db()->prepare('INSERT INTO tts_fila (usuario_id, texto, voz, quem, aprovado) VALUES (?, ?, ?, ?, ?)')
                ->execute([$uid, $limpo, $voz, mb_substr($quem, 0, 40), $aprovado]);
        } catch (Throwable $semColuna) {
            /* SQL 074 NÃO RODADO NÃO PODE MATAR O TTS.

               A coluna é de um recurso novo e opcional. Derrubar por causa
               dela deixaria o streamer com o TTS mudo e sem nenhuma pista
               — e ele nem pediu pra segurar nada. Sem a coluna, enfileira
               como sempre enfileirou; a tela de saúde avisa o que falta. */
            db()->prepare('INSERT INTO tts_fila (usuario_id, texto, voz, quem) VALUES (?, ?, ?, ?)')
                ->execute([$uid, $limpo, $voz, mb_substr($quem, 0, 40)]);
        }
        /* Aqui, e não ao abrir a tela: quem configurou e desistiu não usou
           o recurso, e contar isso inflaria o número justo do jeito que
           faria a gente manter uma coisa que ninguém usa. */
        uso_marca($uid, 'tts');
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
            /* A JANELA CONTA DA APROVAÇÃO, e não da chegada.

               Sem o COALESCE, moderar viraria armadilha: a mensagem
               segurada por quatro minutos já nasceria vencida, e aprovar
               não falaria nada — sem erro, sem aviso, sem pista. */
            'SELECT id, texto, voz, quem FROM tts_fila
              WHERE usuario_id = ? AND falado_em IS NULL AND aprovado = 1
                AND COALESCE(aprovado_em, criado_em)
                    > DATE_SUB(NOW(), INTERVAL ' . TTS_VALE_SEG . ' SECOND)
              ORDER BY id LIMIT ' . max(1, min(10, $quantas))
        );
        $st->execute([$uid]);
        $linhas = $st->fetchAll();
    } catch (Throwable $e) {
        /* Mesmo motivo do INSERT: sem o SQL 074, fala como falava antes. */
        try {
            $st = db()->prepare(
                'SELECT id, texto, voz, quem FROM tts_fila
                  WHERE usuario_id = ? AND falado_em IS NULL
                    AND criado_em > DATE_SUB(NOW(), INTERVAL ' . TTS_VALE_SEG . ' SECOND)
                  ORDER BY id LIMIT ' . max(1, min(10, $quantas))
            );
            $st->execute([$uid]);
            $linhas = $st->fetchAll();
        } catch (Throwable $e2) {
            return [];
        }
    }

    /* A FRASE MONTADA VAI NO 'texto', E NÃO NUM CAMPO NOVO.

       Eu tinha mandado ela num campo 'dizer' pra não mexer no que já
       existia — e um campo novo é justamente o que uma fonte com o código
       antigo em cache não conhece. Ela ignorava o campo e falava só a
       mensagem, que era o defeito que eu estava tentando consertar.

       No 'texto' funciona com qualquer versão da fonte, porque é o campo
       que ela já fala desde o primeiro dia.

       A frase fixa entre o nome e a mensagem não é enfeite: sem ela, uma
       mensagem que começa com "fulano disse que" sairia colada no nome de
       quem resgatou e viraria a fala de dois.

       O texto limpo vai junto, em 'so_texto', pra tela escrever só a
       mensagem — lá o nome já tem linha própria e repetir seria ruído. */
    foreach ($linhas as $i => $l) {
        $linhas[$i]['so_texto'] = $l['texto'];
        if ($l['quem'] !== '') {
            $linhas[$i]['texto'] = $l['quem'] . ' resgatou uma mensagem de voz e falou: ' . $l['texto'];
        }
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
