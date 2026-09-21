ALTER TABLE account_deletion_requests
    MODIFY status ENUM(
        'pending',
        'processing',
        'completed',
        'cancelled',
        'rejected'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN scheduled_for TIMESTAMP NULL AFTER requested_at;

UPDATE account_deletion_requests
SET scheduled_for = DATE_ADD(requested_at, INTERVAL 3 DAY)
WHERE status = 'pending' AND scheduled_for IS NULL;

CREATE INDEX idx_account_deletion_queue
    ON account_deletion_requests (status, scheduled_for);
