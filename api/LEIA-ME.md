# ZocaController — núcleo

O servidor do ZocaController: contas, assinatura, overlays, feed e o relé
entre a ponte do OBS e o painel. Roda em hospedagem compartilhada (PHP 8.3,
MySQL). A visão geral do projeto está em `ARQUITETURA.md`, na raiz.

## Instalar

1. Rodar `sql/schema.sql` e depois cada arquivo numerado de `sql/`, em
   ordem, no phpMyAdmin da Hostinger.
2. `cp api/config.example.php api/config.php` e preencher.
3. Gerar os segredos: `php -r "echo bin2hex(random_bytes(32));"`
4. Cadastrar a URL do webhook no painel do Mercado Pago e copiar a
   "Assinatura secreta" para o `config.php`.

## Como o dinheiro entra

Checkout **hospedado**: o usuário é redirecionado para o domínio do Mercado
Pago, digita o cartão lá, e volta. Nosso servidor nunca vê número de cartão.

Quem libera o acesso é o **webhook**, nunca a volta do navegador — essa URL
qualquer pessoa digita.

## Os dois códigos

- `chave_publica` — vai na URL do overlay, aparece em print e em VOD. Só lê.
- `chave_secreta` — só o painel usa, guardada como hash. Altera.

Se fosse uma chave só, o primeiro print do OBS de um streamer entregaria o
controle do canal dele para quem estivesse assistindo.

## Ordem de construção

O gateway é a ÚLTIMA peça a ligar. O schema e a separação das chaves valem
desde já porque moldam o resto; a integração de pagamento só faz sentido
quando existir algo que valha a pena assinar.
