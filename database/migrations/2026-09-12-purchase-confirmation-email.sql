CREATE TABLE IF NOT EXISTS purchase_email_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    status ENUM(
        'pending',
        'processing',
        'sent',
        'failed',
        'ignored'
    ) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    last_error VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_purchase_email_jobs_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE RESTRICT,

    UNIQUE KEY uq_purchase_email_job_order (order_id),
    KEY idx_purchase_email_job_queue (status, available_at)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

INSERT INTO purchase_email_jobs (order_id, status)
SELECT id, 'pending'
FROM orders
WHERE status = 'paid'
ON DUPLICATE KEY UPDATE
    order_id = VALUES(order_id);
