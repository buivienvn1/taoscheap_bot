SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL UNIQUE,
    username VARCHAR(100) NULL,
    first_name VARCHAR(150) NOT NULL DEFAULT '',
    balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    language VARCHAR(5) NOT NULL DEFAULT 'vi',
    state VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_variant_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    features TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_visible (status, sort_order),
    UNIQUE KEY uq_products_source_variant (source_variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(15,2) NOT NULL,
    supplier_type ENUM('manual', 'api') NOT NULL DEFAULT 'manual',
    provider_code VARCHAR(100) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_variants_product (product_id, status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(30) NOT NULL UNIQUE,
    payment_code VARCHAR(30) NULL UNIQUE,
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
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_orders_variant FOREIGN KEY (variant_id) REFERENCES variants(id),
    UNIQUE KEY uq_orders_request_token (request_token),
    INDEX idx_orders_user (user_id, created_at),
    INDEX idx_orders_status (status, created_at),
    INDEX idx_orders_pending_match (user_id, variant_id, quantity, unit_price, payment_method, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    variant_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    is_sold TINYINT(1) NOT NULL DEFAULT 0,
    order_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sold_at DATETIME NULL,
    CONSTRAINT fk_stocks_variant FOREIGN KEY (variant_id) REFERENCES variants(id),
    CONSTRAINT fk_stocks_order FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_stocks_available (variant_id, is_sold, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    stock_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_deliveries_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_order_deliveries_stock FOREIGN KEY (stock_id) REFERENCES stocks(id),
    UNIQUE KEY uq_order_deliveries_stock (stock_id),
    INDEX idx_order_deliveries_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    CONSTRAINT fk_purchase_requests_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_purchase_requests_variant FOREIGN KEY (variant_id) REFERENCES variants(id),
    CONSTRAINT fk_purchase_requests_order FOREIGN KEY (order_id) REFERENCES orders(id),
    UNIQUE KEY uq_purchase_requests_token (request_token),
    INDEX idx_purchase_requests_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkout_selections (
    user_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, message_id),
    CONSTRAINT fk_checkout_selections_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_checkout_selections_product FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_checkout_selections_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    message_key VARCHAR(100) NOT NULL,
    message_params LONGTEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    CONSTRAINT fk_notification_outbox_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_notification_outbox_event (event_key),
    INDEX idx_notification_outbox_pending (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deposits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_deposits_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_deposits_pending (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('deposit', 'purchase', 'refund', 'adjustment') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    reference_type VARCHAR(30) NULL,
    reference_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_transactions_user (user_id, created_at),
    INDEX idx_transactions_reference (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
    UNIQUE KEY uq_payment_event (provider, provider_event_id),
    INDEX idx_payment_events_order (order_id),
    INDEX idx_payment_events_deposit (deposit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    key_name VARCHAR(100) PRIMARY KEY,
    key_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (key_name, key_value) VALUES
    ('shop_name', 'Foxpay'),
    ('shop_intro', 'FOXPAY - gudang aplikasi digital\nuntuk memenuhi semua kebutuhan\nharian anda.'),
    ('shop_intro_en', '<b>Taos Cheap</b> - digital products for your daily needs.'),
    ('owner_username', '@foxstoreid'),
    ('channel_username', '@foxpayid'),
    ('website', 'foxpay.my.id'),
    ('support_text', 'Liên hệ quản trị viên để được hỗ trợ.'),
    ('support_text_en', 'Contact the administrator for support.'),
    ('min_deposit', '2000')
ON DUPLICATE KEY UPDATE key_name = VALUES(key_name);
