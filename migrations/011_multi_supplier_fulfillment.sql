CREATE TABLE supplier_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    variant_id BIGINT UNSIGNED NOT NULL,
    source_type ENUM('manual', 'api') NOT NULL,
    provider_key VARCHAR(50) NULL,
    remote_product_code VARCHAR(100) NULL,
    priority SMALLINT UNSIGNED NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_sources_priority (variant_id, priority),
    KEY idx_supplier_sources_active (variant_id, status, priority),
    CONSTRAINT fk_supplier_sources_variant
        FOREIGN KEY (variant_id) REFERENCES variants(id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_fulfillment_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    supplier_source_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    provider_order_id VARCHAR(100) NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'unknown') NOT NULL DEFAULT 'pending',
    error_code VARCHAR(100) NULL,
    error_message VARCHAR(255) NULL,
    response_payload MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_attempt_idempotency (idempotency_key),
    UNIQUE KEY uq_supplier_attempt_source (order_id, supplier_source_id),
    KEY idx_supplier_attempt_status (status, updated_at),
    CONSTRAINT fk_supplier_attempt_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_attempt_source
        FOREIGN KEY (supplier_source_id) REFERENCES supplier_sources(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE order_deliveries
    MODIFY COLUMN stock_id BIGINT UNSIGNED NULL,
    ADD COLUMN supplier_source_id BIGINT UNSIGNED NULL AFTER stock_id,
    ADD COLUMN provider_order_id VARCHAR(100) NULL AFTER supplier_source_id,
    ADD KEY idx_order_deliveries_source (supplier_source_id),
    ADD CONSTRAINT fk_order_deliveries_source
        FOREIGN KEY (supplier_source_id) REFERENCES supplier_sources(id)
        ON UPDATE RESTRICT ON DELETE SET NULL;

INSERT INTO supplier_sources (variant_id, source_type, provider_key, remote_product_code, priority, status)
SELECT id,
       supplier_type,
       CASE WHEN supplier_type = 'api' THEN 'sandromania' ELSE NULL END,
       CASE WHEN supplier_type = 'api' THEN provider_code ELSE NULL END,
       CASE WHEN supplier_type = 'manual' THEN 0 ELSE 1 END,
       status
FROM variants;
