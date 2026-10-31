SET NAMES utf8mb4;
SET time_zone = '+07:00';

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER unit_price;
