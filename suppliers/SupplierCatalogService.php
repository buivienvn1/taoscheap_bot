<?php

declare(strict_types=1);

final class SupplierCatalogService
{
    private const LOCK_SECONDS = 120;
    private const COINGECKO_USDT_VND_URL = 'https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=vnd';

    public function __construct(
        private Database $db,
        private SupplierRegistry $registry
    ) {
    }

    /** @return array<string, float> */
    public function exchangeRates(): array
    {
        $row = $this->db->fetch("SELECT key_value FROM settings WHERE key_name='supplier_rate_usdt_vnd'");
        if ($row === null) {
            $row = $this->db->fetch("SELECT key_value FROM settings WHERE key_name='supplier_rate_usdt'");
        }
        $rate = is_numeric($row['key_value'] ?? null) ? (float) $row['key_value'] : 0.0;
        return $rate > 0 ? ['USDT' => $rate, 'USD' => $rate] : [];
    }

    public function saveExchangeRates(array $values): void
    {
        $value = $values['USDT'] ?? null;
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException('invalid_usdt_vnd_rate');
        }
        $this->db->execute(
            "INSERT INTO settings(key_name,key_value) VALUES('supplier_rate_usdt_vnd',?) ON DUPLICATE KEY UPDATE key_value=VALUES(key_value)",
            [number_format((float) $value, 8, '.', '')]
        );
        $this->recalculatePrices();
    }

    public function refreshCoinGeckoRate(): float
    {
        $curl = curl_init(self::COINGECKO_USDT_VND_URL);
        if ($curl === false) throw new RuntimeException('coingecko_curl_init_failed');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'TaosCheapBot/1.0',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false || $status !== 200) {
            throw new RuntimeException('coingecko_request_failed:' . $status . ':' . $error);
        }
        $data = json_decode($body, true);
        $rate = $data['tether']['vnd'] ?? null;
        if (!is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException('coingecko_invalid_rate');
        }
        $this->saveExchangeRates(['USDT' => $rate]);
        return (float) $rate;
    }

    /** @return array{success: bool, providers: int, errors: int} */
    public function refreshInventory(): array
    {
        $providers = 0;
        $errors = 0;
        foreach ($this->registry->all() as $providerKey => $provider) {
            if (!$provider->isConfigured()) continue;
            $providers++;
            if (!$this->syncBalance($providerKey)['success']) $errors++;
            if (!$this->syncProducts($providerKey)['success']) $errors++;
        }
        return ['success' => $providers > 0 && $errors === 0, 'providers' => $providers, 'errors' => $errors];
    }

    public function effectiveStock(array $product, float $balance): int
    {
        $supplierStock = max(0, (int) ($product['stock'] ?? 0));
        $price = is_numeric($product['unit_price'] ?? null) ? (float) $product['unit_price'] : 0.0;
        if (
            $supplierStock === 0
            || $price <= 0
            || $balance <= 0
            || empty($product['is_active'])
            || empty($product['is_available'])
        ) {
            return 0;
        }

        return min($supplierStock, max(0, (int) floor($balance / $price)));
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
        $rate = $this->exchangeRates()['USDT'] ?? null;
        if ($rate === null) return;
        $this->db->execute("UPDATE supplier_products SET currency='USDT',exchange_rate=?,price_vnd=ROUND(unit_price*?,2) WHERE currency IN ('USD','USDT') AND unit_price IS NOT NULL", [$rate, $rate]);
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
