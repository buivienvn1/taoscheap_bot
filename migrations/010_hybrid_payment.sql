-- Hybrid payment: reserve wallet funds and collect only the remaining amount by VietQR.

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS wallet_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER amount,
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER completed_at,
    MODIFY COLUMN payment_method ENUM('wallet', 'vietqr_direct', 'hybrid') NOT NULL;
