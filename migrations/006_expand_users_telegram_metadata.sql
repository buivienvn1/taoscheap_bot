ALTER TABLE users
    ADD COLUMN last_name VARCHAR(150) NULL AFTER first_name,
    ADD COLUMN language_code VARCHAR(20) NULL AFTER language,
    ADD COLUMN is_premium TINYINT(1) NOT NULL DEFAULT 0 AFTER language_code,
    ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0 AFTER is_premium,
    ADD COLUMN raw_data JSON NULL AFTER state;
