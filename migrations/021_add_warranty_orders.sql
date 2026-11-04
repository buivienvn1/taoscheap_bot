ALTER TABLE orders
    ADD COLUMN order_type ENUM('purchase', 'warranty') NOT NULL DEFAULT 'purchase' AFTER order_code,
    ADD COLUMN parent_order_id BIGINT UNSIGNED NULL AFTER order_type,
    ADD COLUMN warranty_source ENUM('manual', 'stock', 'api') NULL AFTER parent_order_id,
    ADD COLUMN warranty_supplier_source_id BIGINT UNSIGNED NULL AFTER warranty_source,
    ADD KEY idx_orders_parent (parent_order_id, id),
    ADD KEY idx_orders_type_status (order_type, status, created_at),
    ADD KEY idx_orders_warranty_source (warranty_supplier_source_id),
    ADD CONSTRAINT fk_orders_parent
        FOREIGN KEY (parent_order_id) REFERENCES orders(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT fk_orders_warranty_source
        FOREIGN KEY (warranty_supplier_source_id) REFERENCES supplier_sources(id)
        ON UPDATE RESTRICT ON DELETE SET NULL;
