SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(30) NULL,
    status ENUM('pending','active','blocked','deleted') NOT NULL DEFAULT 'pending',
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    document_type ENUM('cpf','other') NULL,
    document_value VARCHAR(32) NULL,
    birth_date DATE NULL,
    city VARCHAR(100) NULL,
    state CHAR(2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_profiles_document (document_type, document_value),
    CONSTRAINT fk_user_profiles_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(140) NOT NULL,
    title VARCHAR(200) NOT NULL,
    short_description VARCHAR(500) NULL,
    description TEXT NULL,
    duration_minutes SMALLINT UNSIGNED NULL,
    format VARCHAR(80) NOT NULL DEFAULT 'Online • Moodle',
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    image_path VARCHAR(255) NULL,
    moodle_course_id BIGINT UNSIGNED NULL,
    status ENUM('draft','active','inactive') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_courses_slug (slug),
    UNIQUE KEY uq_courses_moodle_course_id (moodle_course_id),
    KEY idx_courses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    public_id CHAR(36) NOT NULL,
    status ENUM('pending','paid','cancelled','expired','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_public_id (public_id),
    KEY idx_orders_user (user_id),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    course_title_snapshot VARCHAR(200) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    total_price DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_items_order_course (order_id, course_id),
    KEY idx_order_items_course (course_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_order_items_course
        FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('mercado_pago') NOT NULL DEFAULT 'mercado_pago',
    provider_payment_id VARCHAR(100) NULL,
    provider_preference_id VARCHAR(100) NULL,
    provider_status VARCHAR(60) NULL,
    status ENUM('pending','approved','rejected','cancelled','refunded','in_process','unknown') NOT NULL DEFAULT 'pending',
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    payment_method VARCHAR(80) NULL,
    paid_at DATETIME NULL,
    provider_payload LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_provider_payment (provider, provider_payment_id),
    KEY idx_payments_order (order_id),
    KEY idx_payments_status (status),
    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    status ENUM('pending','active','suspended','completed','cancelled') NOT NULL DEFAULT 'pending',
    access_granted_at DATETIME NULL,
    access_revoked_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enrollments_user_course (user_id, course_id),
    KEY idx_enrollments_course (course_id),
    KEY idx_enrollments_order (order_id),
    KEY idx_enrollments_status (status),
    CONSTRAINT fk_enrollments_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_enrollments_course
        FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_enrollments_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moodle_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    moodle_user_id BIGINT UNSIGNED NOT NULL,
    moodle_username VARCHAR(100) NULL,
    sync_status ENUM('pending','synced','error') NOT NULL DEFAULT 'pending',
    last_synced_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_moodle_accounts_user (user_id),
    UNIQUE KEY uq_moodle_accounts_moodle_user (moodle_user_id),
    CONSTRAINT fk_moodle_accounts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consent_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    email VARCHAR(254) NULL,
    consent_type ENUM('privacy_policy','terms_of_use','marketing') NOT NULL,
    policy_version VARCHAR(40) NOT NULL,
    accepted BOOLEAN NOT NULL DEFAULT TRUE,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_consent_records_user (user_id),
    KEY idx_consent_records_type_version (consent_type, policy_version),
    CONSTRAINT fk_consent_records_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    purpose ENUM('email_verification','password_reset') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user_purpose (user_id, purpose),
    KEY idx_auth_tokens_expires (expires_at),
    CONSTRAINT fk_auth_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider ENUM('mercado_pago','moodle') NOT NULL,
    provider_event_id VARCHAR(120) NOT NULL,
    event_type VARCHAR(100) NULL,
    payload LONGTEXT NOT NULL,
    processing_status ENUM('received','processed','ignored','error') NOT NULL DEFAULT 'received',
    processed_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_events_provider_event (provider, provider_event_id),
    KEY idx_webhook_events_processing (processing_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO courses
    (slug, title, short_description, duration_minutes, format, price, currency, image_path, status)
VALUES
    ('gestao-financeira-pessoal', 'Gestão Financeira Pessoal', 'Estratégias práticas para organizar o orçamento, controlar despesas, definir metas e desenvolver hábitos financeiros mais saudáveis.', 60, 'Online • Moodle', 69.90, 'BRL', './assets/images/gestaoFinanceira.png', 'active'),
    ('micro-habitos-pessoais', 'Micro-Hábitos Pessoais: Construindo Mudanças Sustentáveis no Dia a Dia', 'Estratégias práticas para criar e manter hábitos positivos, fortalecer a disciplina e alcançar objetivos de forma consistente.', 60, 'Online • Moodle', 69.90, 'BRL', './assets/images/microPessoais.png', 'active'),
    ('comunicacao-nao-violenta', 'Comunicação Não Violenta na Prática: Transformando Relações Pessoais e Profissionais', 'Aprenda a expressar suas necessidades com clareza, lidar melhor com conflitos e construir relações mais conscientes e respeitosas.', 60, 'Online • Moodle', 69.90, 'BRL', './assets/images/comunicacaoNviolenta.png', 'active'),
    ('regulacao-emocional', 'Regulação Emocional', 'Estratégias práticas para compreender e gerenciar emoções, lidar com situações de estresse e tomar decisões de maneira mais equilibrada.', 60, 'Online • Moodle', 69.90, 'BRL', './assets/images/regulacaoEmocional.png', 'active'),
    ('comunicacao-empatica', 'Comunicação Empática e Escuta Ativa', 'Desenvolva uma comunicação mais clara e respeitosa por meio de técnicas de escuta ativa, empatia e comunicação assertiva.', 60, 'Online • Moodle', 69.90, 'BRL', './assets/images/comunicacaoEmpatica.png', 'active')
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    short_description = VALUES(short_description),
    duration_minutes = VALUES(duration_minutes),
    format = VALUES(format),
    price = VALUES(price),
    currency = VALUES(currency),
    image_path = VALUES(image_path),
    status = VALUES(status),
    updated_at = CURRENT_TIMESTAMP;
