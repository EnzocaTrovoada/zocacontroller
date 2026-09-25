-- Assinatura de verdade: o Mercado Pago cobra sozinho todo mes.
--
-- provedor_id nao servia pra guardar o id da assinatura porque mp_liberar()
-- escreve o id do PAGAMENTO nele a cada cobranca. Sao duas coisas diferentes
-- com tempos diferentes: a assinatura vive anos, o pagamento e de um mes.
ALTER TABLE assinaturas
  ADD COLUMN assinatura_externa VARCHAR(64) NULL AFTER provedor_id,
  ADD COLUMN renova            TINYINT(1)   NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN cancelada_em      DATETIME     NULL AFTER valido_ate,
  ADD KEY ix_assinatura_externa (assinatura_externa);
