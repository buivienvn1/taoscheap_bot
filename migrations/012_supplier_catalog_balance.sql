INSERT INTO settings(key_name,key_value) VALUES
    ('supplier_rate_usd', '25500'),
    ('supplier_rate_usdt', '25500'),
    ('supplier_rate_rub', '280')
ON DUPLICATE KEY UPDATE key_value=key_value;

CREATE TABLE supplier_balances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_key VARCHAR(50) NOT NULL,
    currency VARCHAR(12) NOT NULL,
    amount DECIMAL(24,8) NOT NULL,
    fetched_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_balances_provider_currency (provider_key, currency),
    KEY idx_supplier_balances_fetched (provider_key, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_key VARCHAR(50) NOT NULL,
    remote_product_code VARCHAR(100) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    is_available TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    unit_price DECIMAL(24,8) NULL,
    currency VARCHAR(12) NULL,
    price_kind VARCHAR(50) NOT NULL DEFAULT 'unknown',
    price_vnd DECIMAL(24,2) NULL,
    exchange_rate DECIMAL(24,8) NULL,
    max_quantity INT UNSIGNED NULL,
    raw_payload MEDIUMTEXT NULL,
    fetched_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_products_provider_code (provider_key, remote_product_code),
    KEY idx_supplier_products_search (provider_key, is_active, is_available),
    KEY idx_supplier_products_price (is_active, is_available, price_vnd),
    KEY idx_supplier_products_fetched (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_sync_state (
    provider_key VARCHAR(50) NOT NULL,
    resource_type ENUM('balance', 'products') NOT NULL,
    status ENUM('idle', 'syncing', 'success', 'error') NOT NULL DEFAULT 'idle',
    sync_token CHAR(32) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    successful_at DATETIME NULL,
    error_code VARCHAR(100) NULL,
    error_message VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider_key, resource_type),
    KEY idx_supplier_sync_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
