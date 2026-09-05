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

    'mercadopago' => [
        'access_token'   => '',   // credencial de producao
        'webhook_secret' => '',   // "Assinatura secreta" no painel de webhooks
        'url_retorno'    => 'https://zocahop.com/zocacontroller/obrigado.php',
    ],

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

    // Para onde mandar o streamer depois do login.
    'hub' => 'https://mods.zocahop.com/meu.html',

    // Onde mora o overlay do subathon. O servidor fala com ele para somar
    // tempo, entao o codigo de dono nunca precisa ir numa URL que vaza.
    'relogio_base' => 'https://relogio.zocahop.com',

    // Ninguem perde recurso no meio de uma live por atraso de cobranca.
    'grace_dias' => 3,
];
