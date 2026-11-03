-- Binance Pay USDT payment feed and user-supplied Order ID claims.

INSERT INTO settings(key_name,key_value) VALUES
    ('binance_pay_enabled','0'),
    ('binance_pay_id',''),
    ('binance_pay_rate_usdt_vnd','25500')
ON DUPLICATE KEY UPDATE key_value=VALUES(key_value);

ALTER TABLE orders
    MODIFY COLUMN payment_method ENUM('wallet', 'vietqr_direct', 'hybrid', 'binance_pay') NOT NULL,
    ADD COLUMN IF NOT EXISTS binance_order_id VARCHAR(64) NULL AFTER payment_code,
    ADD COLUMN IF NOT EXISTS binance_usdt_amount DECIMAL(20,8) NULL AFTER binance_order_id;

ALTER TABLE deposits
    ADD COLUMN IF NOT EXISTS payment_method ENUM('vietqr','binance_pay') NOT NULL DEFAULT 'vietqr' AFTER code,
    ADD COLUMN IF NOT EXISTS binance_order_id VARCHAR(64) NULL AFTER payment_method,
    ADD COLUMN IF NOT EXISTS binance_usdt_amount DECIMAL(20,8) NULL AFTER binance_order_id;

CREATE TABLE IF NOT EXISTS binance_pay_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id VARCHAR(100) NOT NULL,
    transaction_number VARCHAR(100) NULL,
    binance_order_id VARCHAR(64) NOT NULL,
    status VARCHAR(30) NOT NULL,
    amount_usdt DECIMAL(20,8) NOT NULL,
    transaction_at VARCHAR(50) NULL,
    claimed_by_user_id BIGINT UNSIGNED NULL,
    claimed_request_type ENUM('deposit','order') NULL,
    claimed_request_id BIGINT UNSIGNED NULL,
    claimed_at DATETIME NULL,
    raw_payload MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_binance_transaction_id (transaction_id),
    UNIQUE KEY uq_binance_order_id (binance_order_id),
    KEY idx_binance_claimed (claimed_by_user_id, claimed_at),
    CONSTRAINT fk_binance_transaction_user
        FOREIGN KEY (claimed_by_user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
