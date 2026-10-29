-- Global DONATE registry and safe third-party payment feed support.
-- Version 008 is intentional: production may already contain a removed migration 007.

CREATE TABLE IF NOT EXISTS payment_code_registry (
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

ALTER TABLE notification_outbox
    MODIFY status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS locked_at DATETIME NULL AFTER attempts,
    ADD COLUMN IF NOT EXISTS next_attempt_at DATETIME NULL AFTER locked_at,
    ADD COLUMN IF NOT EXISTS last_error_code VARCHAR(100) NULL AFTER next_attempt_at;

CREATE TABLE IF NOT EXISTS payment_feed_runs (
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

CREATE TABLE IF NOT EXISTS payment_feed_observations (
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
    CONSTRAINT fk_payment_feed_observations_run
        FOREIGN KEY (run_id) REFERENCES payment_feed_runs(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill every issued deposit code. A collision is quarantined instead of selecting a winner.
INSERT INTO payment_code_registry
    (code, request_type, request_id, status, processed_amount, result_type, processed_at)
SELECT
    UPPER(d.code),
    'deposit',
    d.id,
    CASE WHEN d.status = 'completed' THEN 'processed' ELSE 'reserved' END,
    CASE WHEN d.status = 'completed' THEN d.amount ELSE NULL END,
    CASE WHEN d.status = 'completed' THEN 'legacy_deposit_completed' ELSE NULL END,
    CASE WHEN d.status = 'completed' THEN COALESCE(d.completed_at, d.created_at) ELSE NULL END
FROM deposits d
WHERE d.code IS NOT NULL AND d.code <> ''
ON DUPLICATE KEY UPDATE
    request_type = IF(payment_code_registry.request_type = 'deposit' AND payment_code_registry.request_id = VALUES(request_id), payment_code_registry.request_type, 'ambiguous'),
    request_id = IF(payment_code_registry.request_type = 'deposit' AND payment_code_registry.request_id = VALUES(request_id), payment_code_registry.request_id, NULL),
    status = IF(payment_code_registry.request_type = 'deposit' AND payment_code_registry.request_id = VALUES(request_id), payment_code_registry.status, 'review'),
    result_type = IF(payment_code_registry.request_type = 'deposit' AND payment_code_registry.request_id = VALUES(request_id), payment_code_registry.result_type, 'legacy_code_collision');

-- Delivered orders and orders with a wallet credit are historical financial effects.
-- Other closed orders are quarantined because their status alone is not proof of payment.
INSERT INTO payment_code_registry
    (code, request_type, request_id, status, processed_amount, result_type, processed_at)
SELECT
    UPPER(o.payment_code),
    'order',
    o.id,
    CASE
        WHEN o.status = 'pending' THEN 'reserved'
        WHEN o.status = 'completed' OR o.wallet_credited > 0 THEN 'processed'
        ELSE 'review'
    END,
    CASE
        WHEN o.status = 'completed' THEN o.amount + o.wallet_credited
        WHEN o.wallet_credited > 0 THEN o.wallet_credited
        ELSE NULL
    END,
    CASE
        WHEN o.status = 'completed' THEN 'legacy_order_completed'
        WHEN o.wallet_credited > 0 THEN 'legacy_order_wallet_credit'
        WHEN o.status <> 'pending' THEN 'legacy_order_unverified'
        ELSE NULL
    END,
    CASE
        WHEN o.status = 'completed' OR o.wallet_credited > 0 THEN COALESCE(o.paid_at, o.completed_at, o.created_at)
        ELSE NULL
    END
FROM orders o
WHERE o.payment_code IS NOT NULL AND o.payment_code <> ''
ON DUPLICATE KEY UPDATE
    request_type = IF(payment_code_registry.request_type = 'order' AND payment_code_registry.request_id = VALUES(request_id), payment_code_registry.request_type, 'ambiguous'),
    request_id = IF(payment_code_registry.request_type = 'order' AND payment_code_registry.request_id = VALUES(request_id), payment_code_registry.request_id, NULL),
    status = IF(payment_code_registry.request_type = 'order' AND payment_code_registry.request_id = VALUES(request_id), payment_code_registry.status, 'review'),
    result_type = IF(payment_code_registry.request_type = 'order' AND payment_code_registry.request_id = VALUES(request_id), payment_code_registry.result_type, 'legacy_code_collision');
