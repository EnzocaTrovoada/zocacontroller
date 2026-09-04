<?php
/**
 * Quantos seguidores (ou subs) o canal tem agora.
 *
 * É o que faz a meta se encher sozinha: ninguém digita o número, e ele não
 * envelhece. O overlay não fala com a Twitch — ele pergunta pra cá, e aqui a
 * resposta fica guardada por um minuto.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/twitch.php';
require_once __DIR__ . '/subathon-somar.php';

const CONTAGEM_FONTES = ['seguidores', 'subs', 'viewers'];

/**
 * Devolve o número, ou null se nunca deu pra saber.
 *
 * NUNCA devolve zero por causa de falha: um overlay que zera no meio da live
 * porque a Twitch tossiu é pior do que um overlay parado no número de ontem.
 */
/**
 * A meta de viewers: bateu o alvo, soma tempo e o alvo sobe.
 *
 * O alvo mora no banco e nao na memoria justamente pra disparar UMA vez por
 * travessia. Se ficasse so na tela, cada consulta do overlay somaria tempo de
 * novo enquanto a live estivesse acima do numero — e uma live boa viraria
 * tempo infinito em minutos.
 */
function viewers_meta(int $usuario_id, int $agora): void
{
    $st = db()->prepare(
        'SELECT viewers_alvo, viewers_seg, viewers_passo FROM subathon
          WHERE usuario_id = ? AND ligado = 1'
    );
    $st->execute([$usuario_id]);
    $c = $st->fetch();
    if (!$c || !$c['viewers_alvo'] || !$c['viewers_seg']) return;
    if ($agora < (int) $c['viewers_alvo']) return;

    /* O UPDATE condicional E a trava: quem conseguir mudar a linha e quem
       soma. Duas consultas ao mesmo tempo, so uma passa. */
    $sobe = db()->prepare(
        'UPDATE subathon SET viewers_alvo = viewers_alvo + GREATEST(viewers_passo, 1)
          WHERE usuario_id = ? AND viewers_alvo = ?'
    );
    $sobe->execute([$usuario_id, (int) $c['viewers_alvo']]);
    if (!$sobe->rowCount()) return;

    subathon_somar($usuario_id, [
        'tipo'  => 'viewers',
        'chave' => 'viewers-' . $usuario_id . '-' . $c['viewers_alvo'],
        'quem'  => 'a galera',
        'detalhe' => $c['viewers_alvo'] . ' assistindo',
        'segundos_fixos' => (int) $c['viewers_seg'],
    ]);
}

function contagem(int $usuario_id, string $fonte, int $maxIdade = 60): ?int
{
    if (!in_array($fonte, CONTAGEM_FONTES, true)) return null;

    $st = db()->prepare(
        'SELECT valor, TIMESTAMPDIFF(SECOND, atualizado_em, NOW()) AS idade
           FROM contagens WHERE usuario_id = ? AND fonte = ?'
    );
    $st->execute([$usuario_id, $fonte]);
    $linha = $st->fetch();

    if ($linha && (int) $linha['idade'] < $maxIdade) {
        return (int) $linha['valor'];
    }
    $anterior = $linha ? (int) $linha['valor'] : null;

    try {
        $bid = tw_broadcaster_id($usuario_id);
        if ($fonte === 'viewers') {
            /* Fora do ar a Helix devolve lista vazia, e isso NAO e falha: e
               zero de verdade. Tratar como falha deixaria o numero de ontem
               congelado na tela com a live desligada. */
            [$http, $corpo] = tw_helix($usuario_id, 'GET', 'streams', ['user_id' => $bid, 'first' => 1]);
            $ok = ($http === 200 && isset($corpo['data']));
            if ($ok) $corpo['total'] = (int) ($corpo['data'][0]['viewer_count'] ?? 0);
        } else {
            [$http, $corpo] = $fonte === 'seguidores'
                ? tw_helix($usuario_id, 'GET', 'channels/followers', ['broadcaster_id' => $bid, 'first' => 1])
                : tw_helix($usuario_id, 'GET', 'subscriptions',      ['broadcaster_id' => $bid, 'first' => 1]);
            $ok = ($http === 200 && isset($corpo['total']));
        }
    } catch (Throwable $e) {
        $ok = false;
    }

    if (!$ok) {
        /* Adia a próxima tentativa sem mexer no valor: com a Twitch fora do ar,
           tentar a cada batida do OBS seria bater na porta dela sem parar. */
        if ($anterior !== null) {
            db()->prepare('UPDATE contagens SET atualizado_em = NOW() WHERE usuario_id = ? AND fonte = ?')
                ->execute([$usuario_id, $fonte]);
        }
        return $anterior;
    }

    $valor = (int) $corpo['total'];
    db()->prepare(
        'INSERT INTO contagens (usuario_id, fonte, valor) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW()'
    )->execute([$usuario_id, $fonte, $valor]);

    return $valor;
}
