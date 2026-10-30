<?php

declare(strict_types=1);

final class CupponHubSupplier extends BaseApiSupplier
{
    public function key(): string
    {
        return 'cupponhub';
    }

    protected function requiredConfigKeys(): array
    {
        return ['base_url', 'api_key'];
    }

    public function getBalance(): array
    {
        $response = $this->apiRequest('GET', '?action=balance');
        if ($response['status'] !== 200 || !($response['data']['ok'] ?? false)) {
            throw new RuntimeException('cupponhub_balance_failed');
        }
        return ['USDT' => (string) ($response['data']['balance_usdt'] ?? '0')];
    }

    public function getProducts(): array
    {
        $response = $this->apiRequest('GET', '?action=products');
        if ($response['status'] !== 200 || !($response['data']['ok'] ?? false)) {
            throw new RuntimeException('cupponhub_products_failed');
        }

        $products = [];
        foreach ((array) ($response['data']['products'] ?? []) as $product) {
            if (!is_array($product) || trim((string) ($product['key'] ?? '')) === '') {
                continue;
            }
            $unitPrice = is_numeric($product['price_usdt_from'] ?? null)
                ? number_format((float) $product['price_usdt_from'], 6, '.', '')
                : null;
            $stock = max(0, (int) ($product['stock'] ?? 0));
            $products[] = [
                'code' => (string) $product['key'],
                'name' => trim((string) ($product['name'] ?? $product['key'])),
                'stock' => $stock,
                'available' => $stock > 0 && $unitPrice !== null,
                'unit_price' => $unitPrice,
                'currency' => $unitPrice === null ? null : 'USDT',
                'price_kind' => 'from_quantity_1',
                'max_quantity' => isset($product['max_quantity']) ? max(0, (int) $product['max_quantity']) : null,
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
            return $this->failure('provider_not_configured', "Cuppon'sHub is not configured.", false);
        }
        try {
            $response = $this->apiRequest(
                'POST',
                '?action=purchase',
                ['product' => $remoteProductCode, 'quantity' => $quantity],
                $idempotencyKey
            );
        } catch (Throwable $error) {
            return $this->failure('transport_error', $error->getMessage(), true);
        }

        $data = $response['data'];
        if (in_array($response['status'], [200, 201], true) && ($data['ok'] ?? false) && is_array($data['order'] ?? null)) {
            $order = $data['order'];
            $items = array_values(array_map('strval', (array) ($order['items'] ?? [])));
            if (count($items) !== $quantity) {
                return $this->failure('invalid_delivery', "Cuppon'sHub returned an unexpected delivery quantity.", false, $data);
            }
            return [
                'success' => true,
                'items' => $items,
                'provider_order_id' => (string) ($order['order_id'] ?? ''),
                'raw' => $data,
            ];
        }

        $code = (string) ($data['error'] ?? 'supplier_error');
        return $this->failure(
            $code,
            (string) ($data['message'] ?? $code),
            $response['status'] === 429 || $response['status'] >= 500,
            $data
        );
    }

    /** @return array{status: int, data: array<string, mixed>, raw: string} */
    private function apiRequest(
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null
    ): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('cupponhub_not_configured');
        }
        $headers = ['X-API-Key: ' . $this->config['api_key']];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        return $this->request(
            $method,
            $path,
            $payload === [] ? '' : $this->encodeJson($payload),
            $headers
        );
    }
}
