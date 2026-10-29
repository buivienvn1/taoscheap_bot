ALTER TABLE deposits
    ADD COLUMN reference_code VARCHAR(30) NULL AFTER code,
    ADD COLUMN balance_before DECIMAL(15,2) NULL AFTER amount,
    ADD COLUMN balance_after DECIMAL(15,2) NULL AFTER balance_before,
    ADD UNIQUE KEY uq_deposits_reference_code (reference_code),
    ADD KEY idx_deposits_history (user_id, completed_at, id);

UPDATE deposits
SET reference_code = CONCAT('NAP-', LPAD(id, 6, '0'))
WHERE reference_code IS NULL;

ALTER TABLE transactions
    ADD COLUMN reference_code VARCHAR(30) NULL AFTER reference_id,
    ADD COLUMN balance_before DECIMAL(15,2) NULL AFTER amount,
    ADD COLUMN balance_after DECIMAL(15,2) NULL AFTER balance_before,
    ADD KEY idx_transactions_code (reference_code),
    ADD KEY idx_transactions_history (user_id, created_at, id);

UPDATE transactions t
JOIN deposits d ON d.id = t.reference_id
SET t.reference_code = d.reference_code,
    t.balance_before = d.balance_before,
    t.balance_after = d.balance_after
WHERE t.reference_type = 'deposit'
  AND t.type = 'deposit'
  AND t.reference_code IS NULL;

UPDATE transactions t
JOIN orders o ON o.id = t.reference_id
SET t.reference_code = o.order_code
WHERE t.reference_type = 'order'
  AND t.reference_code IS NULL;

ALTER TABLE notification_outbox
    MODIFY COLUMN user_id BIGINT UNSIGNED NULL,
    ADD COLUMN recipient_type ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER user_id,
    ADD COLUMN telegram_chat_id BIGINT NULL AFTER recipient_type,
    ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT 'vi' AFTER telegram_chat_id,
    ADD COLUMN action_type ENUM('send','edit_or_send') NOT NULL DEFAULT 'send' AFTER language,
    ADD COLUMN target_chat_id BIGINT NULL AFTER action_type,
    ADD COLUMN target_message_id BIGINT NULL AFTER target_chat_id,
    ADD COLUMN sent_message_id BIGINT NULL AFTER target_message_id,
    ADD KEY idx_notification_outbox_recipient (recipient_type, telegram_chat_id, created_at);

UPDATE notification_outbox n
JOIN users u ON u.id = n.user_id
SET n.recipient_type = 'user',
    n.telegram_chat_id = u.telegram_id,
    n.language = IF(u.language IN ('vi','en'), u.language, 'vi')
WHERE n.telegram_chat_id IS NULL;

CREATE TABLE payment_message_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_type ENUM('deposit','order') NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    chat_id BIGINT NOT NULL,
    message_id BIGINT NOT NULL,
    message_type ENUM('photo','text') NOT NULL,
    qr_url TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    cleanup_status ENUM('pending','processing','cleaned','failed','superseded') NOT NULL DEFAULT 'pending',
    cleanup_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cleaned_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_message (chat_id, message_id),
    KEY idx_payment_message_request (request_type, request_id, is_active, id),
    KEY idx_payment_message_cleanup (cleanup_status, id),
    CONSTRAINT fk_payment_message_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
