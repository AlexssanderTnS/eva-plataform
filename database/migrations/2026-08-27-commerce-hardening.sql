-- =========================================================
-- EVA Platform
-- Hardening de concorrência dos webhooks de pagamento
-- =========================================================

ALTER TABLE payment_webhook_events
    MODIFY COLUMN status ENUM(
        'received',
        'processing',
        'processed',
        'ignored',
        'failed'
    ) NOT NULL DEFAULT 'received';
