-- TAOS Shop - Fresh database installation
-- WARNING: This script permanently deletes all existing shop data.

SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS schema_migrations;
DROP TABLE IF EXISTS payment_feed_observations;
DROP TABLE IF EXISTS payment_feed_runs;
DROP TABLE IF EXISTS payment_code_registry;
DROP TABLE IF EXISTS stock_subscriptions;
DROP TABLE IF EXISTS notification_outbox;
DROP TABLE IF EXISTS checkout_selections;
DROP TABLE IF EXISTS purchase_requests;
DROP TABLE IF EXISTS order_deliveries;
DROP TABLE IF EXISTS payment_events;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS deposits;
DROP TABLE IF EXISTS stocks;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS variants;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE schema_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_name VARCHAR(190) NOT NULL,
    checksum CHAR(64) NULL,
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_migrations_name (migration_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    telegram_id BIGINT NOT NULL,
    username VARCHAR(100) NULL,
    first_name VARCHAR(150) NOT NULL DEFAULT '',
    last_name VARCHAR(150) NULL,
    balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    language VARCHAR(5) NOT NULL DEFAULT 'vi',
    language_code VARCHAR(20) NULL,
    is_premium TINYINT(1) NOT NULL DEFAULT 0,
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    state VARCHAR(50) NULL,
    raw_data JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_telegram_id (telegram_id),
    KEY idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_variant_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    features TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_source_variant (source_variant_id),
    KEY idx_products_visible (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE variants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(15,2) NOT NULL,
    supplier_type ENUM('manual', 'api') NOT NULL DEFAULT 'manual',
    provider_code VARCHAR(100) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_variants_product (product_id, status, sort_order),
    CONSTRAINT fk_variants_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products
    ADD CONSTRAINT fk_products_source_variant
        FOREIGN KEY (source_variant_id) REFERENCES variants(id)
        ON UPDATE RESTRICT ON DELETE SET NULL;

CREATE TABLE orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_code VARCHAR(30) NOT NULL,
    payment_code VARCHAR(30) NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(15,2) NULL,
    product_name VARCHAR(150) NULL,
    request_token VARCHAR(64) NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_method ENUM('wallet', 'vietqr_direct') NOT NULL,
    stock_content TEXT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    result_reason VARCHAR(50) NULL,
    wallet_credited DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_order_code (order_code),
    UNIQUE KEY uq_orders_payment_code (payment_code),
    UNIQUE KEY uq_orders_request_token (request_token),
    KEY idx_orders_user (user_id, created_at),
    KEY idx_orders_status (status, created_at),
    KEY idx_orders_pending_match (user_id, variant_id, quantity, unit_price, payment_method, status),
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_orders_variant
        FOREIGN KEY (variant_id) REFERENCES variants(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    variant_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    is_sold TINYINT(1) NOT NULL DEFAULT 0,
    order_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sold_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_stocks_available (variant_id, is_sold, id),
    KEY idx_stocks_order (order_id),
    CONSTRAINT fk_stocks_variant
        FOREIGN KEY (variant_id) REFERENCES variants(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_stocks_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    stock_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_deliveries_stock (stock_id),
    KEY idx_order_deliveries_order (order_id, id),
    CONSTRAINT fk_order_deliveries_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_order_deliveries_stock
        FOREIGN KEY (stock_id) REFERENCES stocks(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_token VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    status ENUM('processing', 'completed', 'failed') NOT NULL DEFAULT 'processing',
    order_id BIGINT UNSIGNED NULL,
    result_reason VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_purchase_requests_token (request_token),
    KEY idx_purchase_requests_user (user_id, created_at),
    KEY idx_purchase_requests_variant (variant_id),
    KEY idx_purchase_requests_order (order_id),
    CONSTRAINT fk_purchase_requests_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_requests_variant
        FOREIGN KEY (variant_id) REFERENCES variants(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_requests_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE checkout_selections (
    user_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, message_id),
    KEY idx_checkout_selections_product (product_id),
    KEY idx_checkout_selections_updated (updated_at),
    CONSTRAINT fk_checkout_selections_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_checkout_selections_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'notified') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_subscriptions_user_product (user_id, product_id),
    KEY idx_stock_subscriptions_pending (product_id, status),
    CONSTRAINT fk_stock_subscriptions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_stock_subscriptions_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    message_key VARCHAR(100) NOT NULL,
    message_params LONGTEXT NOT NULL,
    status ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME NULL,
    next_attempt_at DATETIME NULL,
    last_error_code VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_outbox_event (event_key),
    KEY idx_notification_outbox_pending (status, id),
    KEY idx_notification_outbox_user (user_id, created_at),
    CONSTRAINT fk_notification_outbox_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deposits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_deposits_code (code),
    KEY idx_deposits_pending (user_id, status),
    CONSTRAINT fk_deposits_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('deposit', 'purchase', 'refund', 'adjustment') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    reference_type VARCHAR(30) NULL,
    reference_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transactions_user (user_id, created_at),
    KEY idx_transactions_reference (reference_type, reference_id),
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(30) NOT NULL,
    provider_event_id VARCHAR(150) NOT NULL,
    transfer_content VARCHAR(255) NOT NULL DEFAULT '',
    amount DECIMAL(15,2) NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    deposit_id BIGINT UNSIGNED NULL,
    result_type VARCHAR(50) NULL,
    purchase_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    wallet_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    payload LONGTEXT NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_event (provider, provider_event_id),
    KEY idx_payment_events_order (order_id),
    KEY idx_payment_events_deposit (deposit_id),
    CONSTRAINT fk_payment_events_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT fk_payment_events_deposit
        FOREIGN KEY (deposit_id) REFERENCES deposits(id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_code_registry (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    request_type ENUM('deposit','order','ambiguous') NOT NULL,
    request_id BIGINT UNSIGNED NULL,
    status ENUM('reserved','processed','review') NOT NULL DEFAULT 'reserved',
    processed_amount DECIMAL(15,2) NULL,
    result_type VARCHAR(50) NULL,
    processed_event_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_code_registry_code (code),
    KEY idx_payment_code_registry_request (request_type, request_id),
    KEY idx_payment_code_registry_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_feed_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(30) NOT NULL,
    mode ENUM('read_only','processing') NOT NULL DEFAULT 'read_only',
    status ENUM('running','ok','unsafe','failed') NOT NULL DEFAULT 'running',
    transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
    matched_count INT UNSIGNED NOT NULL DEFAULT 0,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    review_count INT UNSIGNED NOT NULL DEFAULT 0,
    stale TINYINT(1) NOT NULL DEFAULT 0,
    incomplete TINYINT(1) NOT NULL DEFAULT 0,
    source_last_sync_at VARCHAR(50) NULL,
    source_last_sync_ok_at VARCHAR(50) NULL,
    safe_error_code VARCHAR(50) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_payment_feed_runs_provider (provider, started_at),
    KEY idx_payment_feed_runs_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_feed_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(30) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    provider_transaction_id_hash CHAR(64) NULL,
    provider_transaction_number_hash CHAR(64) NULL,
    payment_code VARCHAR(30) NULL,
    amount DECIMAL(15,2) NULL,
    direction VARCHAR(20) NULL,
    transaction_at VARCHAR(50) NULL,
    result_type VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_feed_observation (provider, fingerprint),
    KEY idx_payment_feed_observations_run (run_id),
    KEY idx_payment_feed_observations_code (payment_code, created_at),
    CONSTRAINT fk_payment_feed_observations_run FOREIGN KEY (run_id) REFERENCES payment_feed_runs(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    key_name VARCHAR(100) NOT NULL,
    key_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (migration_name, checksum) VALUES
    ('001_init_schema.sql', NULL),
    ('002_update_welcome_ui.sql', NULL),
    ('003_add_multilingual_settings.sql', NULL),
    ('004_product_quantity_checkout.sql', NULL),
    ('005_stock_subscriptions.sql', NULL),
    ('006_expand_users_telegram_metadata.sql', NULL),
    ('008_third_party_acb_feed.sql', NULL);

INSERT INTO settings (key_name, key_value) VALUES
    ('shop_name', 'TAOS Shop'),
    ('shop_intro', 'Sản phẩm số uy tín, giao hàng tự động.'),
    ('shop_intro_en', 'Trusted digital products with automatic delivery.'),
    ('owner_username', ''),
    ('channel_username', ''),
    ('website', ''),
    ('support_text', 'Liên hệ quản trị viên để được hỗ trợ.'),
    ('support_text_en', 'Contact the administrator for support.'),
    ('min_deposit', '2000');
