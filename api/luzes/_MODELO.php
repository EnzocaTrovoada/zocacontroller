<?php
/**
 * COMO ESCREVER UM DRIVER DE LUZ  —  leia só este arquivo.
 *
 * Este é o contrato inteiro. Pra somar uma marca nova ao ZocaController não
 * é preciso conhecer mais nada do projeto: copie este arquivo, troque o
 * conteúdo, salve como api/luzes/<marca>.php e suba. O site acha sozinho.
 *
 * ---------------------------------------------------------------------
 * O QUE O ARQUIVO PRECISA FAZER
 *
 * Devolver (return) um array com as chaves abaixo. Nada de echo, nada de
 * header, nada de acesso ao banco: o núcleo cuida disso.
 *
 *   id      string curta, só letras minúsculas. Tem que ser igual ao nome do
 *           arquivo sem o .php.
 *   nome    como aparece na tela.
 *   ajuda   uma frase dizendo onde a pessoa pega a credencial.
 *   nuvem   true se fala com a internet, false se fala com a rede de casa.
 *           Hoje só true funciona: a hospedagem não alcança a rede de
 *           ninguém. Um driver local vai ser executado pela ponte, e o
 *           contrato dele é este mesmo.
 *   campos  o que perguntar pra pessoa. Cada campo é
 *           ['chave' => 'token', 'rotulo' => 'Token', 'segredo' => true].
 *           Campo com segredo => true nunca volta pro navegador depois de
 *           salvo. A tela é montada a partir desta lista.
 *   testar  function(array $cfg): array
 *           $cfg tem os campos preenchidos pela pessoa.
 *           Devolve ['ok' => true, 'aparelhos' => [['id'=>'x','nome'=>'Mesa']]]
 *           ou ['ok' => false, 'erro' => 'texto pra quem não é técnico'].
 *   aplicar function(array $cfg, array $ordem, array $aparelhos): array
 *           $ordem é sempre este formato, já normalizado:
 *             acao   'cor' | 'ligar' | 'desligar' | 'piscar'
 *             cor    '#RRGGBB' ou null
 *             brilho 0 a 100 ou null
 *             ms     0 = fica assim; maior que 0 = volta depois desse tempo
 *           $aparelhos são os ids que a pessoa escolheu ([] = todos).
 *           Devolve ['ok' => true] ou ['ok' => false, 'erro' => '...'].
 *
 * ---------------------------------------------------------------------
 * REGRAS QUE NÃO PODEM SER QUEBRADAS
 *
 *   Use luz_http() pra falar com a internet. Ela já tem tempo limite. Uma
 *   chamada sem tempo limite trava a requisição inteira quando a marca cai,
 *   e o chat da pessoa fica esperando.
 *
 *   Nunca devolva a credencial em lugar nenhum, nem dentro de 'erro'.
 *
 *   Nunca faça mais de uma chamada por aparelho quando a marca aceitar
 *   vários de uma vez: isso é o que estoura o limite de chamadas delas.
 *
 *   O texto de 'erro' é lido por quem transmite, não por programador.
 *   "Token recusado — gere outro" serve; "HTTP 401" não.
 *
 * ---------------------------------------------------------------------
 * O QUE JÁ EXISTE
 *
 *   lifx.php   nuvem, token
 *   govee.php  nuvem, chave de API
 *
 * O que ainda não existe e cabe aqui: Tuya/Smart Life (nuvem, precisa de
 * conta de desenvolvedor Tuya), Philips Hue remoto (nuvem, precisa de app
 * aprovado), Nanoleaf, WLED e Hue local (rede de casa, dependem da ponte).
 * A Alexa não tem caminho: a API dela é pra quem fabrica aparelho, não pra
 * quem controla.
 */

return [
    'id'     => 'modelo',
    'nome'   => 'Modelo',
    'ajuda'  => 'Este arquivo é exemplo e não aparece na tela.',
    'nuvem'  => true,
    'oculto' => true,

    'campos' => [
        ['chave' => 'token', 'rotulo' => 'Token', 'segredo' => true],
    ],

    'testar' => function (array $cfg): array {
        return ['ok' => false, 'erro' => 'Modelo não controla nada.'];
    },

    'aplicar' => function (array $cfg, array $ordem, array $aparelhos): array {
        return ['ok' => false, 'erro' => 'Modelo não controla nada.'];
    },
];
