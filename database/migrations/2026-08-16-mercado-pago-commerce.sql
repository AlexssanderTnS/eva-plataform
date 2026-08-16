-- =========================================================
-- EVA Platform
-- Estrutura comercial: cursos, pedidos, pagamentos e acessos
-- =========================================================

CREATE TABLE IF NOT EXISTS courses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    slug VARCHAR(120) NOT NULL,
    title VARCHAR(255) NOT NULL,

    price DECIMAL(10, 2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',

    moodle_course_id BIGINT UNSIGNED NULL,

    status ENUM(
        'active',
        'inactive'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_courses_slug (slug),
    UNIQUE KEY uq_courses_moodle_course_id (moodle_course_id)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,

    /*
     * Identificador público e seguro usado como
     * external_reference no Mercado Pago.
     *
     * Nunca enviaremos o ID incremental do banco
     * como referência externa.
     */
    external_reference VARCHAR(64) NOT NULL,

    amount DECIMAL(10, 2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',

    status ENUM(
        'created',
        'pending',
        'paid',
        'failed',
        'cancelled',
        'refunded'
    ) NOT NULL DEFAULT 'created',

    paid_at TIMESTAMP NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_orders_course
        FOREIGN KEY (course_id)
        REFERENCES courses(id)
        ON DELETE RESTRICT,

    UNIQUE KEY uq_orders_external_reference (external_reference),

    KEY idx_orders_user_id (user_id),
    KEY idx_orders_course_id (course_id),
    KEY idx_orders_status (status)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id BIGINT UNSIGNED NOT NULL,

    provider ENUM(
        'mercado_pago'
    ) NOT NULL DEFAULT 'mercado_pago',

    /*
     * IDs fornecidos pelo Mercado Pago.
     *
     * Nem sempre todos estarão disponíveis
     * imediatamente após a criação da order.
     */
    provider_order_id VARCHAR(100) NULL,
    provider_payment_id VARCHAR(100) NULL,

    /*
     * A chave enviada no header X-Idempotency-Key.
     */
    idempotency_key VARCHAR(64) NOT NULL,

    payment_method_id VARCHAR(80) NULL,
    payment_method_type VARCHAR(80) NULL,

    status VARCHAR(80) NOT NULL DEFAULT 'created',
    status_detail VARCHAR(120) NULL,

    amount DECIMAL(10, 2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',

    approved_at TIMESTAMP NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE RESTRICT,

    UNIQUE KEY uq_payments_idempotency_key (idempotency_key),

    UNIQUE KEY uq_payments_provider_payment (
        provider,
        provider_payment_id
    ),

    KEY idx_payments_order_id (order_id),
    KEY idx_payments_provider_order_id (provider_order_id),
    KEY idx_payments_status (status)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS course_access (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,

    /*
     * Pedido que originou a liberação.
     */
    order_id BIGINT UNSIGNED NOT NULL,

    status ENUM(
        'pending',
        'active',
        'revoked'
    ) NOT NULL DEFAULT 'pending',

    granted_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_course_access_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_course_access_course
        FOREIGN KEY (course_id)
        REFERENCES courses(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_course_access_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE RESTRICT,

    /*
     * Um usuário possui um estado único de acesso
     * por curso. Novos pedidos podem reativar o acesso,
     * sem criar várias matrículas conflitantes.
     */
    UNIQUE KEY uq_course_access_user_course (
        user_id,
        course_id
    ),

    KEY idx_course_access_status (status)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    provider ENUM(
        'mercado_pago'
    ) NOT NULL DEFAULT 'mercado_pago',

    /*
     * Identificador utilizado para evitar processar
     * a mesma notificação mais de uma vez.
     */
    event_key VARCHAR(190) NOT NULL,

    topic VARCHAR(80) NULL,
    resource_id VARCHAR(120) NULL,

    status ENUM(
        'received',
        'processed',
        'ignored',
        'failed'
    ) NOT NULL DEFAULT 'received',

    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,

    UNIQUE KEY uq_payment_webhook_event (
        provider,
        event_key
    ),

    KEY idx_payment_webhook_status (status)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- Catálogo inicial EVA
-- =========================================================

INSERT INTO courses (
    slug,
    title,
    price,
    currency,
    status
)
VALUES
(
    'gestao-financeira-pessoal',
    'Gestão Financeira Pessoal',
    69.90,
    'BRL',
    'active'
),
(
    'micro-habitos-pessoais',
    'Micro-Hábitos Pessoais: Construindo Mudanças Sustentáveis no Dia a Dia',
    69.90,
    'BRL',
    'active'
),
(
    'comunicacao-nao-violenta',
    'Comunicação Não Violenta na Prática: Transformando Relações Pessoais e Profissionais',
    69.90,
    'BRL',
    'active'
),
(
    'regulacao-emocional',
    'Regulação Emocional',
    69.90,
    'BRL',
    'active'
),
(
    'comunicacao-empatica',
    'Comunicação Empática e Escuta Ativa',
    69.90,
    'BRL',
    'active'
)
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    price = VALUES(price),
    currency = VALUES(currency);