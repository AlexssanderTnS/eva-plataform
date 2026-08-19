-- =========================================================
-- EVA Platform
-- Ajustes de comércio para Mercado Pago Checkout Pro
-- =========================================================

ALTER TABLE orders
    ADD COLUMN provider_preference_id VARCHAR(100) NULL
        AFTER external_reference,
    ADD UNIQUE KEY uq_orders_provider_preference_id (
        provider_preference_id
    );

ALTER TABLE payments
    CHANGE COLUMN provider_order_id
        provider_merchant_order_id VARCHAR(100) NULL,
    MODIFY COLUMN idempotency_key VARCHAR(64) NULL;

ALTER TABLE payments
    DROP INDEX idx_payments_provider_order_id,
    ADD KEY idx_payments_provider_merchant_order_id (
        provider_merchant_order_id
    );
