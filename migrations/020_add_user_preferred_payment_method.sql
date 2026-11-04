-- Remember the user's latest payment method to prioritize dual-currency prices.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS preferred_payment_method ENUM('bank','binance_pay') NOT NULL DEFAULT 'bank' AFTER language;
