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

    'mercadopago' => [
        'access_token'   => '',   // credencial de producao
        'webhook_secret' => '',   // "Assinatura secreta" no painel de webhooks
        'url_retorno'    => 'https://zocahop.com/zocacontroller/obrigado.php',
    ],

    // Ninguem perde recurso no meio de uma live por atraso de cobranca.
    'grace_dias' => 3,
];
