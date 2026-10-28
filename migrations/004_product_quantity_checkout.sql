SET NAMES utf8mb4;
SET time_zone = '+07:00';

DELIMITER $$

DROP PROCEDURE IF EXISTS add_column_if_missing$$
CREATE PROCEDURE add_column_if_missing(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN column_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @statement = CONCAT(
            'ALTER TABLE `', table_name_value, '` ADD COLUMN `',
            column_name_value, '` ', column_definition_value
        );
        PREPARE prepared_statement FROM @statement;
        EXECUTE prepared_statement;
        DEALLOCATE PREPARE prepared_statement;
    END IF;
END$$

DROP PROCEDURE IF EXISTS add_index_if_missing$$
CREATE PROCEDURE add_index_if_missing(
    IN table_name_value VARCHAR(64),
    IN index_name_value VARCHAR(64),
    IN index_definition_value TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND INDEX_NAME = index_name_value
    ) THEN
        SET @statement = CONCAT(
            'ALTER TABLE `', table_name_value, '` ADD ', index_definition_value
        );
        PREPARE prepared_statement FROM @statement;
        EXECUTE prepared_statement;
        DEALLOCATE PREPARE prepared_statement;
    END IF;
END$$

CALL add_column_if_missing('products', 'source_variant_id', 'BIGINT UNSIGNED NULL AFTER `id`')$$
CALL add_index_if_missing('products', 'uq_products_source_variant', 'UNIQUE KEY `uq_products_source_variant` (`source_variant_id`)')$$

CALL add_column_if_missing('orders', 'quantity', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `variant_id`')$$
CALL add_column_if_missing('orders', 'unit_price', 'DECIMAL(15,2) NULL AFTER `quantity`')$$
CALL add_column_if_missing('orders', 'product_name', 'VARCHAR(150) NULL AFTER `unit_price`')$$
CALL add_column_if_missing('orders', 'request_token', 'VARCHAR(64) NULL AFTER `product_name`')$$
CALL add_column_if_missing('orders', 'result_reason', 'VARCHAR(50) NULL AFTER `status`')$$
CALL add_column_if_missing('orders', 'wallet_credited', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `result_reason`')$$
CALL add_index_if_missing('orders', 'uq_orders_request_token', 'UNIQUE KEY `uq_orders_request_token` (`request_token`)')$$
CALL add_index_if_missing('orders', 'idx_orders_pending_match', 'KEY `idx_orders_pending_match` (`user_id`, `variant_id`, `quantity`, `unit_price`, `payment_method`, `status`)')$$

CALL add_column_if_missing('payment_events', 'order_id', 'BIGINT UNSIGNED NULL AFTER `amount`')$$
CALL add_column_if_missing('payment_events', 'deposit_id', 'BIGINT UNSIGNED NULL AFTER `order_id`')$$
CALL add_column_if_missing('payment_events', 'result_type', 'VARCHAR(50) NULL AFTER `deposit_id`')$$
CALL add_column_if_missing('payment_events', 'purchase_amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `result_type`')$$
CALL add_column_if_missing('payment_events', 'wallet_amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `purchase_amount`')$$
CALL add_index_if_missing('payment_events', 'idx_payment_events_order', 'KEY `idx_payment_events_order` (`order_id`)')$$
CALL add_index_if_missing('payment_events', 'idx_payment_events_deposit', 'KEY `idx_payment_events_deposit` (`deposit_id`)')$$

CREATE TABLE IF NOT EXISTS order_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    stock_id BIGINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_deliveries_order
        FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_order_deliveries_stock
        FOREIGN KEY (stock_id) REFERENCES stocks(id),
    UNIQUE KEY uq_order_deliveries_stock (stock_id),
    INDEX idx_order_deliveries_order (order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$$

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
    CONSTRAINT fk_purchase_requests_user
        FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_purchase_requests_variant
        FOREIGN KEY (variant_id) REFERENCES variants(id),
    CONSTRAINT fk_purchase_requests_order
        FOREIGN KEY (order_id) REFERENCES orders(id),
    UNIQUE KEY uq_purchase_requests_token (request_token),
    INDEX idx_purchase_requests_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$$

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$$

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci$$

CALL add_column_if_missing('purchase_requests', 'unit_price', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `quantity`')$$

DELIMITER ;

UPDATE orders o
JOIN variants v ON v.id = o.variant_id
JOIN products p ON p.id = v.product_id
SET o.quantity = CASE WHEN o.quantity = 0 THEN 1 ELSE o.quantity END,
    o.unit_price = COALESCE(o.unit_price, o.amount),
    o.product_name = COALESCE(NULLIF(o.product_name, ''), p.name)
WHERE o.unit_price IS NULL
   OR o.product_name IS NULL
   OR o.product_name = ''
   OR o.quantity = 0;

INSERT IGNORE INTO order_deliveries (order_id, stock_id, content)
SELECT o.id, s.id, s.content
FROM orders o
JOIN stocks s ON s.order_id = o.id
WHERE o.status = 'completed';

UPDATE products p
JOIN (
    SELECT product_id, MIN(id) AS first_variant_id
    FROM variants
    GROUP BY product_id
) first_variant ON first_variant.product_id = p.id
SET p.source_variant_id = first_variant.first_variant_id
WHERE p.source_variant_id IS NULL;

INSERT INTO products (
    source_variant_id,
    name,
    description,
    features,
    sort_order,
    status,
    created_at,
    updated_at
)
SELECT
    v.id,
    CASE
        WHEN TRIM(v.name) = '' OR TRIM(v.name) = TRIM(p.name) THEN p.name
        ELSE CONCAT(p.name, ' · ', v.name)
    END,
    p.description,
    p.features,
    p.sort_order + v.sort_order,
    CASE WHEN p.status = 1 AND v.status = 1 THEN 1 ELSE 0 END,
    v.created_at,
    v.updated_at
FROM variants v
JOIN products p ON p.id = v.product_id
LEFT JOIN products existing_product ON existing_product.source_variant_id = v.id
WHERE existing_product.id IS NULL;

UPDATE variants v
JOIN products p ON p.source_variant_id = v.id
SET v.product_id = p.id,
    v.name = p.name,
    v.sort_order = 0;

DROP PROCEDURE IF EXISTS add_column_if_missing;
DROP PROCEDURE IF EXISTS add_index_if_missing;
