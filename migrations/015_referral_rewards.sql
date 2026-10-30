ALTER TABLE transactions MODIFY type ENUM('deposit','purchase','refund','adjustment','referral_reward') NOT NULL;

CREATE TABLE IF NOT EXISTS referral_codes (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    code CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY uq_referral_code (code),
    CONSTRAINT fk_referral_code_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inviter_id BIGINT UNSIGNED NOT NULL,
    invitee_id BIGINT UNSIGNED NOT NULL,
    reward_amount BIGINT UNSIGNED NOT NULL,
    channel_id VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'waiting',
    gate_passed_at DATETIME NULL,
    last_gate_check_at DATETIME NULL,
    eligible_order_id BIGINT UNSIGNED NULL,
    transaction_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    UNIQUE KEY uq_referral_invitee (invitee_id),
    UNIQUE KEY uq_referral_order (eligible_order_id),
    UNIQUE KEY uq_referral_transaction (transaction_id),
    KEY idx_referral_inviter (inviter_id,id),
    KEY idx_referral_queue (status,id),
    CONSTRAINT fk_referral_inviter FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_invitee FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_order FOREIGN KEY (eligible_order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_order_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    referral_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    result VARCHAR(20) NOT NULL,
    checked_at DATETIME NOT NULL,
    reason VARCHAR(50) NOT NULL,
    review_reason VARCHAR(500) NULL,
    reviewed_by VARCHAR(100) NULL,
    reviewed_at DATETIME NULL,
    UNIQUE KEY uq_referral_check_order (order_id),
    KEY idx_referral_check (referral_id,id),
    CONSTRAINT fk_referral_check_referral FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_check_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_daily_usage (
    usage_day DATE NOT NULL,
    inviter_id BIGINT UNSIGNED NOT NULL,
    reward_count INT UNSIGNED NOT NULL DEFAULT 0,
    reward_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (usage_day,inviter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings(key_name,key_value) VALUES ('referral_config','{"enabled":false,"reward":0,"daily_limit":0,"daily_budget":0,"channel_id":"","channel_url":"","bot_username":"","instructions":""}');
