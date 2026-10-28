CREATE TABLE IF NOT EXISTS stock_subscriptions (
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
