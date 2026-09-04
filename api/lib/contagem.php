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

const CONTAGEM_FONTES = ['seguidores', 'subs'];

/**
 * Devolve o número, ou null se nunca deu pra saber.
 *
 * NUNCA devolve zero por causa de falha: um overlay que zera no meio da live
 * porque a Twitch tossiu é pior do que um overlay parado no número de ontem.
 */
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
        [$http, $corpo] = $fonte === 'seguidores'
            ? tw_helix($usuario_id, 'GET', 'channels/followers', ['broadcaster_id' => $bid, 'first' => 1])
            : tw_helix($usuario_id, 'GET', 'subscriptions',      ['broadcaster_id' => $bid, 'first' => 1]);
        $ok = ($http === 200 && isset($corpo['total']));
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
