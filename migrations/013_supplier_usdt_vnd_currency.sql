INSERT INTO settings(key_name,key_value)
SELECT 'supplier_rate_usdt_vnd', key_value
FROM settings
WHERE key_name='supplier_rate_usdt'
ON DUPLICATE KEY UPDATE key_value=VALUES(key_value);

INSERT INTO supplier_balances(provider_key,currency,amount,fetched_at)
SELECT provider_key,'USDT',amount,fetched_at
FROM supplier_balances
WHERE currency='USD'
ON DUPLICATE KEY UPDATE
    amount=VALUES(amount),
    fetched_at=VALUES(fetched_at);

UPDATE supplier_products
SET currency='USDT'
WHERE currency='USD';
