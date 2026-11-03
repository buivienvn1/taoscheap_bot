-- Durable Binance Pay Order ID queue for delayed third-party feed synchronization.

CREATE TABLE IF NOT EXISTS binance_pending_claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    chat_id BIGINT NOT NULL,
    message_id BIGINT NULL,
    binance_order_id VARCHAR(64) NOT NULL,
    request_type ENUM('deposit','order') NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','processing','claimed','expired','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(100) NULL,
    last_checked_at DATETIME NULL,
    claimed_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_binance_pending_order_id (binance_order_id),
    KEY idx_binance_pending_due (status, expires_at, id),
    KEY idx_binance_pending_user (user_id, status, id),
    KEY idx_binance_pending_request (request_type, request_id, status),
    CONSTRAINT fk_binance_pending_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
