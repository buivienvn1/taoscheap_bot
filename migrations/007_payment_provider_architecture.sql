SET NAMES utf8mb4;
SET time_zone = '+07:00';

ALTER TABLE payment_events
    DROP INDEX uq_payment_event,
    ADD COLUMN destination_account VARCHAR(190) NOT NULL DEFAULT '' AFTER provider_event_id,
    ADD COLUMN currency VARCHAR(12) NOT NULL DEFAULT 'VND' AFTER amount,
    ADD COLUMN direction VARCHAR(20) NOT NULL DEFAULT 'incoming' AFTER currency,
    ADD COLUMN occurred_at DATETIME NULL AFTER direction,
    ADD UNIQUE KEY uq_payment_event (provider, destination_account, provider_event_id),
    ADD KEY idx_payment_events_occurred (provider, occurred_at);

ALTER TABLE notification_outbox
    MODIFY COLUMN status ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    ADD COLUMN last_attempt_at DATETIME NULL AFTER attempts,
    ADD COLUMN last_error VARCHAR(190) NULL AFTER last_attempt_at;

CREATE TABLE payment_source_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(30) NOT NULL,
    source_account VARCHAR(190) NOT NULL,
    provider_transaction_id VARCHAR(150) NOT NULL,
    direction VARCHAR(20) NOT NULL,
    amount DECIMAL(24,8) NOT NULL,
    currency VARCHAR(12) NOT NULL,
    reference_text VARCHAR(500) NOT NULL DEFAULT '',
    occurred_at DATETIME NULL,
    discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    raw_payload LONGTEXT NULL,
    processing_status ENUM('observed', 'processed', 'ignored', 'review', 'failed') NOT NULL DEFAULT 'observed',
    payment_event_id BIGINT UNSIGNED NULL,
    result_message VARCHAR(190) NULL,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_source_transaction (provider, source_account, provider_transaction_id),
    KEY idx_payment_source_processing (provider, processing_status, id),
    KEY idx_payment_source_occurred (provider, source_account, occurred_at),
    CONSTRAINT fk_payment_source_event
        FOREIGN KEY (payment_event_id) REFERENCES payment_events(id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_sync_states (
    provider VARCHAR(30) NOT NULL,
    source_account VARCHAR(190) NOT NULL,
    last_success_at DATETIME NULL,
    last_window_from DATETIME NULL,
    last_window_to DATETIME NULL,
    last_error_code VARCHAR(100) NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider, source_account)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
