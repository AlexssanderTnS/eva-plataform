CREATE TABLE IF NOT EXISTS moodle_provisioning_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_access_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    action ENUM('provision', 'revoke') NOT NULL,
    status ENUM(
        'pending',
        'processing',
        'completed',
        'failed',
        'ignored'
    ) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    last_error VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_moodle_jobs_course_access
        FOREIGN KEY (course_access_id)
        REFERENCES course_access(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_moodle_jobs_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE RESTRICT,

    UNIQUE KEY uq_moodle_job_scope (
        course_access_id,
        order_id,
        action
    ),
    KEY idx_moodle_job_queue (
        status,
        available_at
    )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

UPDATE courses
SET moodle_course_id = 2
WHERE slug = 'gestao-financeira-pessoal';
