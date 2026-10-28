SET NAMES utf8mb4;

INSERT INTO settings (key_name, key_value) VALUES
    ('shop_name', 'Foxpay'),
    ('shop_intro', 'FOXPAY - gudang aplikasi digital\nuntuk memenuhi semua kebutuhan\nharian anda.'),
    ('owner_username', '@foxstoreid'),
    ('channel_username', '@foxpayid'),
    ('website', 'foxpay.my.id')
ON DUPLICATE KEY UPDATE key_value = VALUES(key_value);
