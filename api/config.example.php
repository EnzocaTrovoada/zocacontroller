<?php
// Copie para config.php e preencha. config.php NUNCA vai para o git.
// Gere os segredos com: php -r "echo bin2hex(random_bytes(32));"

return [
    'db' => [
        'host'    => 'localhost',
        'nome'    => 'u000000_zocacontroller',
        'usuario' => '',
        'senha'   => '',
    ],

    // Assina os links temporarios do painel.
    'segredo_links' => '',

    'twitch' => [
        // Publico, pode ficar no codigo.
        'client_id'     => 'zl7mv5lvq7kafaz2sphw2t4x7lvd5k',

        // SECRETO. Pegue em dev.twitch.tv/console/apps e cole aqui.
        // Se vazar, gere outro la — nao da para "desvazar".
        'client_secret' => '',

        // Tem que bater EXATAMENTE com o cadastrado no app da Twitch.
        // api.zocahop.com e o subdominio da Hostinger; mods.zocahop.com nao
        // serve, porque aquele aponta para o GitHub Pages.
        'redirect_uri'  => 'https://api.zocahop.com/entrar.php',
    ],

    // Os overlays moram noutro dominio, entao o navegador exige liberacao
    // explicita. Nada de "*": estes endpoints recebem chave em cabecalho.
    'origens' => [
        'https://mods.zocahop.com',
        'https://zocahop.com',
    ],

    /* Cobranca.

       'ligado' FALSO deixa a estrutura inteira no ar sem existir jeito de
       alguem ser cobrado. Ligar e a ULTIMA coisa a fazer, depois do teste de
       ponta a ponta passar.

       'modo' NAO troca de endereco: o Mercado Pago desligou o ambiente de
       sandbox, e hoje teste e producao usam a mesma API e o mesmo checkout.
       O que separa os dois e so qual credencial esta carregada aqui.

       O 'modo' serve pra duas coisas nossas: marcar as linhas de assinatura
       como ensaio, e fazer o painel ensinar o caminho da janela anonima em
       vez de abrir o checkout numa aba comum.

       access_token e webhook_secret sao PARES: misturar um de teste com um
       de producao da erro de assinatura sem explicacao nenhuma. */
    'mercadopago' => [
        'ligado'         => false,
        'modo'           => 'teste',   // teste | producao
        'access_token'   => '',        // TEST-... no modo teste, APP_USR-... em producao
        'webhook_secret' => '',        // "Assinatura secreta" no painel de webhooks
        'url_retorno'    => 'https://mods.zocahop.com/obrigado.html',
        /* Esta pagina vive junto do painel, no GitHub Pages, e sobe sozinha
           com o commit. Endereco fora dali daria uma pagina a mais pra
           lembrar de subir na mao — e ela e o destino de quem acabou de
           pagar, o pior lugar possivel pra dar 404. */
    ],

    /* Segredo do agendador da vitrine.

       A escolha do canal em destaque custa dezenas de pedidos a Twitch, e
       por isso ela roda uma vez por dia pelo cron da hospedagem, de
       madrugada — assim ja esta pronta quando alguem abre a pagina.

       No hPanel: Avancado > Trabalhos Cron > uma vez por dia, com
         curl -s "https://api.zocahop.com/vitrine.php?cron=SEGREDO" > /dev/null

       Gere com: php -r "echo bin2hex(random_bytes(16));" */
    'vitrine_cron' => '',

    // O proprio endereco desta API. A Twitch precisa dele para entregar os
    // avisos do EventSub, e tem que ser https com certificado valido.
    'api_base' => 'https://api.zocahop.com',

    /* Spotify, pra overlay de "tocando agora".
       Crie um app em developer.spotify.com/dashboard e ponha como Redirect URI
       exatamente: https://api.zocahop.com/spotify.php
       Sem isso a overlay de musica simplesmente nao aparece nas opcoes. */
    'spotify' => [
        'client_id'     => '',
        'client_secret' => '',
    ],

    /* Kick. Crie o app em kick.com > Configuracoes > Developer.
       Redirect URL:  https://api.zocahop.com/kick.php
       Webhook URL:   https://api.zocahop.com/kick-eventos.php
       Escopos: user:read channel:read events:subscribe chat:write

       O Kick usa OAuth 2.1 com PKCE. O secret continua sendo necessario
       porque o cliente aqui e o SERVIDOR, e servidor guarda segredo. */
    'kick' => [
        'client_id'     => '01M1S27GS0SKR83KMJZY8C0J5R',
        'client_secret' => '',
    ],

    /* YouTube. So a chave de API — a contagem de inscritos e dado publico e
       nao precisa de OAuth. Crie em console.cloud.google.com, ative a YouTube
       Data API v3 e restrinja a chave a ela.

       Cada leitura custa 1 unidade e a cota padrao e 10.000 por dia, para
       TODOS os usuarios juntos: com atualizacao de minuto em minuto isso da
       umas sete pessoas transmitindo seis horas. Se crescer, aumente o
       intervalo antes de pedir cota extra ao Google. */
    'youtube' => [
        'api_key' => '',
    ],

    /* Last.fm. So a chave: o que a pessoa esta ouvindo e dado publico e a
       leitura nao pede autenticacao nenhuma. Crie em
       last.fm/api/account/create — sai na hora, sem revisao.

       E o que faz a overlay de musica funcionar pra QUALQUER usuario. O
       Spotify continua existindo por causa do !pular, !fila e !like, mas o
       app dele em modo de desenvolvimento atende cinco contas e todo o resto
       recebe 403. */
    'lastfm' => [
        'api_key' => '',
    ],

    // Para onde mandar o streamer depois do login.
    'hub' => 'https://mods.zocahop.com/meu.html',

    // Onde mora o overlay do subathon. O servidor fala com ele para somar
    // tempo, entao o codigo de dono nunca precisa ir numa URL que vaza.
    'relogio_base' => 'https://relogio.zocahop.com',

    // Ninguem perde recurso no meio de uma live por atraso de cobranca.
    'grace_dias' => 3,
];
