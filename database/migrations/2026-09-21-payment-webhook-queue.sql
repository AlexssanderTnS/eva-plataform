ALTER TABLE payment_webhook_events
    ADD COLUMN attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0
        AFTER status,
    ADD COLUMN available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        AFTER attempts,
    ADD COLUMN started_at TIMESTAMP NULL
        AFTER available_at,
    ADD COLUMN last_error VARCHAR(1000) NULL
        AFTER processed_at,
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        AFTER last_error,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
        AFTER created_at;

ALTER TABLE payment_webhook_events
    DROP INDEX idx_payment_webhook_status,
    ADD KEY idx_payment_webhook_queue (status, available_at);
