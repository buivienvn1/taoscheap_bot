<?php

declare(strict_types=1);

final class SandromaniaSupplier extends BaseApiSupplier
{
    public function key(): string
    {
        return 'sandromania';
    }

    protected function requiredConfigKeys(): array
    {
        return ['base_url', 'api_key', 'api_secret'];
    }

    public function getBalance(): array
    {
        $response = $this->signedRequest('GET', '/api/v1/balance');
        if ($response['status'] !== 200 || !($response['data']['ok'] ?? false)) {
            throw new RuntimeException('sandromania_balance_failed');
        }
        return ['USDT' => (string) ($response['data']['balance_usd'] ?? '0')];
    }

    public function getProducts(): array
    {
        $response = $this->signedRequest('GET', '/api/v1/products');
        if ($response['status'] !== 200 || !($response['data']['ok'] ?? false)) {
            throw new RuntimeException('sandromania_products_failed');
        }

        $products = [];
        foreach ((array) ($response['data']['products'] ?? []) as $product) {
            if (!is_array($product) || trim((string) ($product['id'] ?? '')) === '') {
                continue;
            }
            $unitPrice = is_numeric($product['price_usd'] ?? null)
                ? number_format((float) $product['price_usd'], 6, '.', '')
                : null;
            $stock = max(0, (int) ($product['stock'] ?? 0));
            $products[] = [
                'code' => (string) $product['id'],
                'name' => trim((string) ($product['title'] ?? $product['id'])),
                'stock' => $stock,
                'available' => (bool) ($product['available'] ?? ($stock > 0)),
                'unit_price' => $unitPrice,
                'currency' => $unitPrice === null ? null : 'USDT',
                'price_kind' => 'quantity_1',
                'max_quantity' => null,
                'raw' => $product,
            ];
        }
        return $products;
    }

    public function getStock(string $remoteProductCode): int
    {
        foreach ($this->getProducts() as $product) {
            if ($product['code'] === $remoteProductCode) {
                return $product['stock'];
            }
        }
        return 0;
    }

    public function deliver(string $remoteProductCode, int $quantity, string $idempotencyKey): array
    {
        if (!$this->isConfigured()) {
            return $this->failure('provider_not_configured', 'Sandromania is not configured.', false);
        }
        try {
            $response = $this->signedRequest(
                'POST',
                '/api/v1/orders',
                ['product_id' => ctype_digit($remoteProductCode) ? (int) $remoteProductCode : $remoteProductCode, 'quantity' => $quantity],
                $idempotencyKey
            );
        } catch (Throwable $error) {
            return $this->failure('transport_error', $error->getMessage(), true);
        }

        $data = $response['data'];
        if (in_array($response['status'], [200, 201], true) && ($data['ok'] ?? false) && is_array($data['order'] ?? null)) {
            $order = $data['order'];
            $items = is_array($order['delivery'] ?? null) ? array_values($order['delivery']) : [];
            if ($items === [] && trim((string) ($order['delivery_text'] ?? '')) !== '') {
                $items = preg_split('/\R/u', trim((string) $order['delivery_text'])) ?: [];
            }
            $items = array_values(array_filter(array_map('strval', $items), static fn (string $item): bool => $item !== ''));
            if (count($items) !== $quantity) {
                return $this->failure('invalid_delivery', 'Sandromania returned an unexpected delivery quantity.', false, $data);
            }
            return [
                'success' => true,
                'items' => $items,
                'provider_order_id' => (string) ($order['id'] ?? ''),
                'raw' => $data,
            ];
        }

        $code = (string) ($data['error'] ?? $data['code'] ?? 'supplier_error');
        return $this->failure(
            $code,
            (string) ($data['message'] ?? $code),
            $response['status'] === 429 || $response['status'] >= 500,
            $data
        );
    }

    /** @return array{status: int, data: array<string, mixed>, raw: string} */
    private function signedRequest(
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null
    ): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('sandromania_not_configured');
        }
        $rawBody = $payload === [] ? '' : $this->encodeJson($payload);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(18));
        $signedValue = implode('.', [$timestamp, $nonce, strtoupper($method), $path, $rawBody]);
        $signature = hash_hmac('sha256', $signedValue, (string) $this->config['api_secret']);
        $headers = [
            'X-Api-Key: ' . $this->config['api_key'],
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature: ' . $signature,
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        return $this->request($method, $path, $rawBody, $headers);
    }
}
