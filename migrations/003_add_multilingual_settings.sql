SET NAMES utf8mb4;

INSERT INTO settings (key_name, key_value) VALUES
    ('shop_intro_en', '<b>Taos Cheap</b> - digital products for your daily needs.'),
    ('support_text_en', 'Contact the administrator for support.')
ON DUPLICATE KEY UPDATE key_name = VALUES(key_name);
