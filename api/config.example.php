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

    // Onde mora o overlay do subathon. O servidor fala com ele para somar
    // tempo, entao o codigo de dono nunca precisa ir numa URL que vaza.
    'relogio_base' => 'https://relogio.zocahop.com',

    // Ninguem perde recurso no meio de uma live por atraso de cobranca.
    'grace_dias' => 3,
];
