/* ZocaController - cupons, parceiros e comissoes.

   Comentario em bloco de proposito: se as quebras de linha se perderem no
   copiar e colar, um comentario de -- engoliria o comando seguinte.

   TRES COISAS DIFERENTES, E VALE SEPARAR:

     parceiro  quem divulga e recebe por isso
     cupom     o codigo que a pessoa digita; pode ou nao ter dono
     comissao  o que se deve a um parceiro por UMA venda especifica

   Cupom sem parceiro e promocao normal ("PRIMEIRAMES"). Cupom com parceiro
   e link de afiliado: alem do desconto pra quem compra, gera divida pra
   quem indicou.

   O desconto e aplicado NO NOSSO LADO, montando a cobranca com o preco ja
   abatido. O Mercado Pago tem um campo de cupom proprio na tela deles, mas
   aquele e o sistema deles, com as promocoes deles — nao tem como um codigo
   nosso entrar la. */

CREATE TABLE IF NOT EXISTS parceiros (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(80)  NOT NULL,
  contato      VARCHAR(160) NULL,               /* onde falar e como pagar */
  comissao_pct DECIMAL(5,2) NOT NULL DEFAULT 0, /* 10.00 = dez por cento */
  ligado       TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* O codigo e a chave primaria e fica sempre em MAIUSCULA: quem digita nao
   vai lembrar da caixa, e duas linhas 'VERAO' e 'verao' seriam dois cupons
   diferentes pra quem olha de fora, um so pra quem digita. */
CREATE TABLE IF NOT EXISTS cupons (
  codigo      VARCHAR(24) NOT NULL PRIMARY KEY,
  descricao   VARCHAR(120) NULL,
  tipo        ENUM('percentual','valor') NOT NULL DEFAULT 'percentual',
  valor       INT UNSIGNED NOT NULL,            /* por cento, ou centavos */
  parceiro_id INT UNSIGNED NULL,
  usos_max    INT UNSIGNED NULL,                /* NULL = sem limite */
  usos        INT UNSIGNED NOT NULL DEFAULT 0,
  vale_ate    DATETIME    NULL,                 /* NULL = nao vence */
  ligado      TINYINT(1)  NOT NULL DEFAULT 1,
  criado_em   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_parceiro (parceiro_id),
  CONSTRAINT fk_cupom_parceiro FOREIGN KEY (parceiro_id)
    REFERENCES parceiros (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Uma comissao por venda, e o UNIQUE e o que garante isso.

   O webhook do Mercado Pago entrega o mesmo aviso mais de uma vez — e sem
   esta restricao, cada reentrega criaria uma divida nova pro mesmo
   pagamento. Dinheiro contado duas vezes e o tipo de erro que so aparece na
   hora de pagar o parceiro. */
CREATE TABLE IF NOT EXISTS comissoes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parceiro_id    INT UNSIGNED NOT NULL,
  assinatura_id  INT UNSIGNED NOT NULL,
  codigo         VARCHAR(24)  NOT NULL,
  base_centavos  INT UNSIGNED NOT NULL,   /* o que entrou de fato */
  valor_centavos INT UNSIGNED NOT NULL,   /* o que se deve ao parceiro */
  estornada      TINYINT(1)   NOT NULL DEFAULT 0,
  pago_em        DATETIME     NULL,
  criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_assinatura (assinatura_id),
  KEY ix_parceiro (parceiro_id, pago_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/* Qual cupom foi usado nesta venda. Fica na propria assinatura porque e
   fato daquela compra, e nao estado do cupom. */
ALTER TABLE assinaturas
  ADD COLUMN cupom VARCHAR(24) NULL DEFAULT NULL;

/* PRO DADO A MAO, COM PRAZO.

   Diferente de 'beta': beta e quem chegou antes da cobranca existir e nao
   perde nada nunca. Isto aqui e "eu te dou Pro ate tal dia" — cortesia,
   parceria, compensacao por um problema. Ter os dois separados e o que
   permite responder depois por que cada pessoa tem acesso. */
ALTER TABLE usuarios
  ADD COLUMN cortesia_ate DATETIME NULL DEFAULT NULL;
