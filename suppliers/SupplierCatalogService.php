<?php

declare(strict_types=1);

final class SupplierCatalogService
{
    private const LOCK_SECONDS = 120;

    public function __construct(
        private Database $db,
        private SupplierRegistry $registry
    ) {
    }

    /** @return array<string, float> */
    public function exchangeRates(): array
    {
        $rows = $this->db->fetchAll("SELECT key_name,key_value FROM settings WHERE key_name LIKE 'supplier_rate_%'");
        $rates = [];
        foreach ($rows as $row) {
            $currency = strtoupper(substr((string) $row['key_name'], strlen('supplier_rate_')));
            if ($currency !== '' && is_numeric($row['key_value']) && (float) $row['key_value'] > 0) {
                $rates[$currency] = (float) $row['key_value'];
            }
        }
        return $rates;
    }

    public function saveExchangeRates(array $values): void
    {
        $allowed = ['USD', 'USDT', 'RUB'];
        $this->db->transaction(function (Database $db) use ($values, $allowed): void {
            foreach ($allowed as $currency) {
                $value = $values[$currency] ?? null;
                if (!is_numeric($value) || (float) $value <= 0) {
                    continue;
                }
                $db->execute(
                    'INSERT INTO settings(key_name,key_value) VALUES(?,?) ON DUPLICATE KEY UPDATE key_value=VALUES(key_value)',
                    ['supplier_rate_' . strtolower($currency), number_format((float) $value, 8, '.', '')]
                );
            }
        });
        $this->recalculatePrices();
    }

    /** @return array{success: bool, message: string} */
    public function syncBalance(string $providerKey): array
    {
        $provider = $this->registry->get($providerKey);
        if ($provider === null || !$provider->isConfigured()) {
            return ['success' => false, 'message' => 'provider_not_configured'];
        }
        $token = $this->claim($providerKey, 'balance');
        if ($token === null) {
            return ['success' => false, 'message' => 'sync_in_progress'];
        }
        try {
            $balances = $provider->getBalance();
            $this->db->transaction(function (Database $db) use ($providerKey, $balances, $token): void {
                if (!$this->owns($providerKey, 'balance', $token, $db)) {
                    throw new RuntimeException('stale_sync');
                }
                foreach ($balances as $currency => $amount) {
                    if (!is_numeric($amount)) continue;
                    $db->execute(
                        'INSERT INTO supplier_balances(provider_key,currency,amount,fetched_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE amount=VALUES(amount),fetched_at=VALUES(fetched_at)',
                        [$providerKey, strtoupper((string) $currency), (string) $amount]
                    );
                }
                $this->finish($providerKey, 'balance', $token, null, $db);
            });
            return ['success' => true, 'message' => 'sync_success'];
        } catch (Throwable $error) {
            $this->finish($providerKey, 'balance', $token, $error);
            return ['success' => false, 'message' => $error->getMessage()];
        }
    }

    /** @return array{success: bool, message: string, count?: int} */
    public function syncProducts(string $providerKey): array
    {
        $provider = $this->registry->get($providerKey);
        if ($provider === null || !$provider->isConfigured()) {
            return ['success' => false, 'message' => 'provider_not_configured'];
        }
        $token = $this->claim($providerKey, 'products');
        if ($token === null) {
            return ['success' => false, 'message' => 'sync_in_progress'];
        }
        try {
            $products = $provider->getProducts();
            $rates = $this->exchangeRates();
            $this->db->transaction(function (Database $db) use ($providerKey, $products, $rates, $token): void {
                if (!$this->owns($providerKey, 'products', $token, $db)) {
                    throw new RuntimeException('stale_sync');
                }
                $seen = [];
                foreach ($products as $product) {
                    $code = trim((string) ($product['code'] ?? ''));
                    if ($code === '') continue;
                    $seen[] = $code;
                    $currency = strtoupper((string) ($product['currency'] ?? '')) ?: null;
                    $price = is_numeric($product['unit_price'] ?? null) ? (float) $product['unit_price'] : null;
                    $rate = $currency !== null && isset($rates[$currency]) ? $rates[$currency] : null;
                    $priceVnd = $price !== null && $rate !== null ? round($price * $rate, 2) : null;
                    $raw = json_encode($product['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $db->execute(
                        'INSERT INTO supplier_products(provider_key,remote_product_code,product_name,stock,is_available,is_active,unit_price,currency,price_kind,price_vnd,exchange_rate,max_quantity,raw_payload,fetched_at) VALUES(?,?,?,?,?,1,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE product_name=VALUES(product_name),stock=VALUES(stock),is_available=VALUES(is_available),is_active=1,unit_price=VALUES(unit_price),currency=VALUES(currency),price_kind=VALUES(price_kind),price_vnd=VALUES(price_vnd),exchange_rate=VALUES(exchange_rate),max_quantity=VALUES(max_quantity),raw_payload=VALUES(raw_payload),fetched_at=VALUES(fetched_at)',
                        [$providerKey, $code, (string) ($product['name'] ?? $code), max(0, (int) ($product['stock'] ?? 0)), !empty($product['available']) ? 1 : 0, $price, $currency, (string) ($product['price_kind'] ?? 'unknown'), $priceVnd, $rate, $product['max_quantity'] ?? null, $raw === false ? null : $raw]
                    );
                }
                if ($seen === []) {
                    $db->execute('UPDATE supplier_products SET is_active=0 WHERE provider_key=?', [$providerKey]);
                } else {
                    $placeholders = implode(',', array_fill(0, count($seen), '?'));
                    $db->execute('UPDATE supplier_products SET is_active=0 WHERE provider_key=? AND remote_product_code NOT IN (' . $placeholders . ')', array_merge([$providerKey], $seen));
                }
                $this->finish($providerKey, 'products', $token, null, $db);
            });
            return ['success' => true, 'message' => 'sync_success', 'count' => count($products)];
        } catch (Throwable $error) {
            $this->finish($providerKey, 'products', $token, $error);
            return ['success' => false, 'message' => $error->getMessage()];
        }
    }

    public function recalculatePrices(): void
    {
        foreach ($this->exchangeRates() as $currency => $rate) {
            $this->db->execute('UPDATE supplier_products SET exchange_rate=?,price_vnd=ROUND(unit_price*?,2) WHERE currency=? AND unit_price IS NOT NULL', [$rate, $rate, $currency]);
        }
    }

    private function claim(string $providerKey, string $resource): ?string
    {
        $token = bin2hex(random_bytes(16));
        return $this->db->transaction(function (Database $db) use ($providerKey, $resource, $token): ?string {
            $row = $db->fetch('SELECT status,started_at FROM supplier_sync_state WHERE provider_key=? AND resource_type=? FOR UPDATE', [$providerKey, $resource]);
            if ($row !== null && $row['status'] === 'syncing' && strtotime((string) $row['started_at']) > time() - self::LOCK_SECONDS) {
                return null;
            }
            $db->execute(
                "INSERT INTO supplier_sync_state(provider_key,resource_type,status,sync_token,started_at,error_code,error_message) VALUES(?,?,'syncing',?,NOW(),NULL,NULL) ON DUPLICATE KEY UPDATE status='syncing',sync_token=VALUES(sync_token),started_at=NOW(),error_code=NULL,error_message=NULL",
                [$providerKey, $resource, $token]
            );
            return $token;
        });
    }

    private function owns(string $providerKey, string $resource, string $token, ?Database $db = null): bool
    {
        $row = ($db ?? $this->db)->fetch('SELECT sync_token FROM supplier_sync_state WHERE provider_key=? AND resource_type=?', [$providerKey, $resource]);
        return $row !== null && hash_equals((string) $row['sync_token'], $token);
    }

    private function finish(string $providerKey, string $resource, string $token, ?Throwable $error = null, ?Database $db = null): void
    {
        $database = $db ?? $this->db;
        if (!$this->owns($providerKey, $resource, $token, $database)) return;
        $database->execute(
            $error === null
                ? "UPDATE supplier_sync_state SET status='success',sync_token=NULL,completed_at=NOW(),successful_at=NOW(),error_code=NULL,error_message=NULL WHERE provider_key=? AND resource_type=? AND sync_token=?"
                : "UPDATE supplier_sync_state SET status='error',sync_token=NULL,completed_at=NOW(),error_code=?,error_message=? WHERE provider_key=? AND resource_type=? AND sync_token=?",
            $error === null
                ? [$providerKey, $resource, $token]
                : [$error::class, substr($error->getMessage(), 0, 255), $providerKey, $resource, $token]
        );
    }
}
