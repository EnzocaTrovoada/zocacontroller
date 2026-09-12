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
 * MARCA QUE NÃO DÁ TOKEN PRA COLAR
 *
 * Algumas marcas — a Philips Hue é uma — não entregam credencial nenhuma
 * pra pessoa. Quem pergunta a senha é a marca, no site dela, e o que volta
 * pra cá é um código. Nesse caso 'campos' fica VAZIO e o arquivo ganha um
 * bloco 'oauth' com três funções:
 *
 *   entrar  function(string $estado): string
 *           Devolve a URL pra onde mandar a pessoa. O $estado vem assinado
 *           pelo núcleo e tem que ir junto: é ele que prova, na volta, que
 *           foi esta pessoa que pediu. Nunca invente outro.
 *
 *   voltar  function(array $query): array
 *           $query é o que a marca mandou de volta ($_GET). Devolve
 *           ['ok' => true, 'config' => [...]] — e essa config é o que fica
 *           guardado e chega depois no 'testar' e no 'aplicar'. Ou
 *           ['ok' => false, 'erro' => 'texto pra quem não é técnico'].
 *
 *   renovar function(array $cfg): ?array
 *           Só quando o acesso vence. Devolve a config nova, ou null se
 *           não deu. Pra o núcleo saber a hora, ponha em 'config' a chave
 *           'expira' com o horário unix em que o acesso morre — sem ela,
 *           renovar nunca é chamado.
 *
 * O endereço de volta é sempre o mesmo, pra qualquer marca:
 * https://api.zocahop.com/luzes.php — cadastre exatamente esse no site
 * de quem fornece a API.
 *
 * ---------------------------------------------------------------------
 * REGRAS QUE NÃO PODEM SER QUEBRADAS
 *
 *   Use luz_http() pra falar com a internet. Ela já tem tempo limite. Uma
 *   chamada sem tempo limite trava a requisição inteira quando a marca cai,
 *   e o chat da pessoa fica esperando. Quando a marca exigir senha no
 *   esquema Digest, use luz_http_digest() — mesma coisa, com a ida e volta
 *   do desafio feita pelo curl.
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
 *   hue.php    nuvem, autorização (o exemplo vivo do bloco 'oauth')
 *   tuya.php   nuvem, Access ID e Secret — atende Positivo Casa
 *              Inteligente, Avant Neo, Smart Life e as lâmpadas genéricas,
 *              que são todas Tuya por dentro
 *
 * O que ainda não existe e cabe aqui: Nanoleaf, WLED e Hue local (rede de
 * casa, dependem da ponte). A TP-Link Tapo não tem API oficial nenhuma, só
 * bibliotecas que imitam o aplicativo — e isso quebra a cada atualização
 * deles. A Alexa também não tem caminho: a API dela é pra quem fabrica
 * aparelho, não pra quem controla.
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
