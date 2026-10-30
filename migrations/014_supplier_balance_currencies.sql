INSERT INTO supplier_balances(provider_key,currency,amount,fetched_at)
SELECT provider_key,'USDT',amount,fetched_at
FROM supplier_balances
WHERE currency='USD'
ON DUPLICATE KEY UPDATE
    amount=VALUES(amount),
    fetched_at=VALUES(fetched_at);

DELETE FROM supplier_balances
WHERE currency NOT IN ('USDT','VND');
