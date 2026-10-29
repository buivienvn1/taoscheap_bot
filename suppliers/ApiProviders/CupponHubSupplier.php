<?php

declare(strict_types=1);

final class CupponHubSupplier extends BaseApiSupplier
{
    public function key(): string
    {
        return 'cupponhub';
    }

    public function getBalance(): array
    {
        $response = $this->apiRequest('GET', '?action=balance');
        if ($response['status'] !== 200 || !($response['data']['ok'] ?? false)) {
            throw new RuntimeException('cupponhub_balance_failed');
        }
        return ['USDT' => (string) ($response['data']['balance_usdt'] ?? '0')];
    }

    public function getStock(string $remoteProductCode): int
    {
        $response = $this->apiRequest('GET', '?action=products');
        if ($response['status'] !== 200 || !($response['data']['ok'] ?? false)) {
            throw new RuntimeException('cupponhub_products_failed');
        }
        foreach ((array) ($response['data']['products'] ?? []) as $product) {
            if ((string) ($product['key'] ?? '') === $remoteProductCode) {
                return max(0, (int) ($product['stock'] ?? 0));
            }
        }
        return 0;
    }

    public function deliver(string $remoteProductCode, int $quantity, string $idempotencyKey): array
    {
        if (!$this->isConfigured(['base_url', 'api_key'])) {
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
        if (!$this->isConfigured(['base_url', 'api_key'])) {
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
