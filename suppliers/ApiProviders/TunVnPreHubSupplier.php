<?php

declare(strict_types=1);

final class TunVnPreHubSupplier extends BaseApiSupplier
{
    public const DEFAULT_BASE_URL = 'https://tunvnmmo.duckdns.org';
    public const MAX_QUANTITY = 100;

    /** @param ?Closure(string, string, string, list<string>): array{status: int, data: array<string, mixed>, raw: string} $transport */
    public function __construct(
        array $config,
        private ?Closure $transport = null
    ) {
        parent::__construct($config);
    }

    public function key(): string
    {
        return 'tunvn_prehub';
    }

    public function name(): string
    {
        return (string) ($this->config['name'] ?? '@TunVN_PreHub_bot');
    }

    protected function requiredConfigKeys(): array
    {
        return ['api_key'];
    }

    public function isConfigured(): bool
    {
        if (!($this->config['enabled'] ?? false)) {
            return false;
        }
        return trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    public function getBalance(): array
    {
        $response = $this->apiRequest('GET', '/api/balance');
        $data = $response['data'];
        if ($response['status'] !== 200) {
            $msg = (string) ($data['message'] ?? 'tunvn_prehub_balance_failed');
            throw new RuntimeException($msg);
        }

        $balances = [];
        if (isset($data['balance_vnd']) && is_numeric($data['balance_vnd'])) {
            $balances['VND'] = (string) $data['balance_vnd'];
        }
        if (isset($data['balance_usdt']) && is_numeric($data['balance_usdt'])) {
            $balances['USDT'] = (string) $data['balance_usdt'];
        }

        return $balances;
    }

    public function getProducts(): array
    {
        $response = $this->apiRequest('GET', '/api/products');
        $data = $response['data'];
        if ($response['status'] !== 200) {
            $msg = (string) ($data['message'] ?? 'tunvn_prehub_products_failed');
            throw new RuntimeException($msg);
        }

        $rawProducts = (array) ($data['products'] ?? $data);
        $products = [];
        foreach ($rawProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $code = trim((string) ($product['id'] ?? $product['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $unitPrice = null;
            $currency = null;
            if (isset($product['price_vnd']) && is_numeric($product['price_vnd'])) {
                $unitPrice = (string) $product['price_vnd'];
                $currency = 'VND';
            } elseif (isset($product['price_usdt']) && is_numeric($product['price_usdt'])) {
                $unitPrice = (string) $product['price_usdt'];
                $currency = 'USDT';
            }

            $stock = max(0, (int) ($product['stock'] ?? 0));
            $products[] = [
                'code' => $code,
                'name' => trim((string) ($product['name'] ?? $code)),
                'stock' => $stock,
                'available' => $stock > 0 && $unitPrice !== null,
                'unit_price' => $unitPrice,
                'currency' => $currency,
                'price_kind' => 'unit_price',
                'max_quantity' => self::MAX_QUANTITY,
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
            return $this->failure('provider_not_configured', '@TunVN_PreHub_bot is not configured.', false);
        }

        if ($quantity <= 0) {
            return $this->failure('invalid_quantity', 'Quantity must be greater than zero.', false);
        }

        if ($quantity > self::MAX_QUANTITY) {
            return $this->failure('max_quantity_exceeded', 'Maximum ' . self::MAX_QUANTITY . ' items per purchase.', false);
        }

        $currency = strtolower((string) ($this->config['currency'] ?? 'vnd'));
        $payload = [
            'product_id' => is_numeric($remoteProductCode) ? (int) $remoteProductCode : $remoteProductCode,
            'quantity' => $quantity,
            'currency' => $currency,
        ];

        try {
            $response = $this->apiRequest('POST', '/api/buy', $payload, $idempotencyKey);
        } catch (Throwable $error) {
            return $this->failure('transport_error', $error->getMessage(), true);
        }

        $status = $response['status'];
        $data = $response['data'];
        $isSuccess = ($status === 200 || $status === 201) && (($data['status'] ?? '') === 'success' || isset($data['items']));

        if ($isSuccess) {
            $items = [];
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    if (is_array($item)) {
                        $items[] = implode('|', array_filter(array_map('strval', $item)));
                    } else {
                        $line = trim((string) $item);
                        if ($line !== '') {
                            $items[] = $line;
                        }
                    }
                }
            } elseif (isset($data['item']) && is_string($data['item'])) {
                $items = array_values(array_filter(array_map('trim', explode("\n", $data['item']))));
            }

            if (count($items) !== $quantity) {
                return $this->failure(
                    'invalid_delivery',
                    '@TunVN_PreHub_bot returned unexpected delivery quantity.',
                    false,
                    $data
                );
            }

            return [
                'success' => true,
                'items' => $items,
                'provider_order_id' => (string) ($data['order_id'] ?? ''),
                'raw' => $data,
            ];
        }

        $code = (string) ($data['status'] ?? 'supplier_error');
        $message = (string) ($data['message'] ?? $data['error'] ?? $code);
        $retryable = $status === 429 || $status >= 500;

        return $this->failure($code, $message, $retryable, $data);
    }

    /** @return array{status: int, data: array<string, mixed>, raw: string} */
    private function apiRequest(
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null
    ): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('tunvn_prehub_not_configured');
        }

        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        $headers = [
            'X-API-Key: ' . $apiKey,
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $rawBody = $payload === [] ? '' : $this->encodeJson($payload);

        if ($this->transport !== null) {
            return ($this->transport)($method, $path, $rawBody, $headers);
        }

        return $this->request($method, $path, $rawBody, $headers);
    }
}
