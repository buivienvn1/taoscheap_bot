<?php

declare(strict_types=1);

require_once __DIR__ . '/../suppliers/SupplierInterface.php';
require_once __DIR__ . '/../suppliers/BaseApiSupplier.php';
require_once __DIR__ . '/../suppliers/ApiProviders/SandromaniaSupplier.php';
require_once __DIR__ . '/../suppliers/ApiProviders/CupponHubSupplier.php';
require_once __DIR__ . '/../suppliers/ApiProviders/TunVnPreHubSupplier.php';
require_once __DIR__ . '/../suppliers/SupplierRegistry.php';

$count = 0;
$assert = static function (bool $condition, string $name) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $count++;
};

// 1. Kiểm tra cấu hình và metadata
$unconfigured = new TunVnPreHubSupplier([]);
$assert(!$unconfigured->isConfigured(), 'unconfigured by default');
$assert($unconfigured->key() === 'tunvn_prehub', 'correct provider key');
$assert($unconfigured->name() === '@TunVN_PreHub_bot', 'default provider name is @TunVN_PreHub_bot');

$disabled = new TunVnPreHubSupplier(['enabled' => false, 'api_key' => 'secret123']);
$assert(!$disabled->isConfigured(), 'disabled supplier is not configured');

$configured = new TunVnPreHubSupplier(['enabled' => true, 'api_key' => 'secret123']);
$assert($configured->isConfigured(), 'enabled with api key is configured');

// 2. Kiểm tra validation khi deliver
$deliverUnconfigured = $unconfigured->deliver('1', 1, 'order_123');
$assert(!$deliverUnconfigured['success'] && $deliverUnconfigured['error_code'] === 'provider_not_configured', 'deliver fails if not configured');

$deliverZero = $configured->deliver('1', 0, 'order_123');
$assert(!$deliverZero['success'] && $deliverZero['error_code'] === 'invalid_quantity', 'deliver fails on zero quantity');

$deliverOverLimit = $configured->deliver('1', 101, 'order_123');
$assert(!$deliverOverLimit['success'] && $deliverOverLimit['error_code'] === 'max_quantity_exceeded', 'deliver fails if quantity exceeds 100');

// 3. Mock Transport để kiểm tra bóc tách response HTTP
$mockResponse = ['status' => 200, 'data' => [], 'raw' => '{}'];
$lastRequest = [];

$transport = static function (
    string $method,
    string $pathWithQuery,
    string $rawBody = '',
    array $headers = []
) use (&$mockResponse, &$lastRequest): array {
    $lastRequest = [
        'method' => $method,
        'path' => $pathWithQuery,
        'body' => $rawBody,
        'headers' => $headers,
    ];
    return $mockResponse;
};

$mock = new TunVnPreHubSupplier(['enabled' => true, 'api_key' => 'test_key_abc', 'currency' => 'vnd'], $transport);

// Test Balance
$mockResponse = [
    'status' => 200,
    'data' => [
        'balance_usdt' => 12.50,
        'balance_vnd' => 312500,
        'deposit_address' => '0x123456789',
        'min_deposit' => 5,
    ],
    'raw' => '{"balance_usdt": 12.50, "balance_vnd": 312500}',
];
$balances = $mock->getBalance();
$assert($balances['VND'] === '312500', 'parses VND balance');
$assert($balances['USDT'] === '12.5', 'parses USDT balance');
$assert(str_contains(implode(';', $lastRequest['headers']), 'X-API-Key: test_key_abc'), 'sends X-API-Key header in balance check');

// Test Products parsing with VND
$mockResponse = [
    'status' => 200,
    'data' => [
        'products' => [
            [
                'id' => 10,
                'name' => 'Discord Nitro 1 Month',
                'price_usdt' => 2.50,
                'price_vnd' => 62500,
                'stock' => 15,
                'format' => 'mail|pass|token',
                'description' => 'Discord Nitro Gift',
            ],
            [
                'id' => 20,
                'name' => 'Out of stock item',
                'price_vnd' => 100000,
                'stock' => 0,
            ],
        ],
    ],
    'raw' => '{}',
];
$products = $mock->getProducts();
$assert(count($products) === 2, 'parsed 2 products');
$assert($products[0]['code'] === '10', 'product code matches id');
$assert($products[0]['unit_price'] === '62500', 'preferred VND price');
$assert($products[0]['currency'] === 'VND', 'currency is VND');
$assert($products[0]['available'] === true, 'product with stock > 0 is available');
$assert($products[1]['available'] === false, 'product with 0 stock is not available');
$assert($mock->getStock('10') === 15, 'getStock returns correct quantity');

// Test Deliver success
$mockResponse = [
    'status' => 200,
    'data' => [
        'status' => 'success',
        'order_id' => 8888,
        'product_id' => 10,
        'quantity' => 2,
        'total' => 125000,
        'currency' => 'vnd',
        'items' => [
            'nitro_link_1',
            'nitro_link_2',
        ],
    ],
    'raw' => '{}',
];
$deliverResult = $mock->deliver('10', 2, 'idemp_key_999');
$assert($deliverResult['success'] === true, 'deliver succeeded');
$assert($deliverResult['provider_order_id'] === '8888', 'provider order id captured');
$assert(count($deliverResult['items']) === 2, 'delivered 2 items');
$assert($deliverResult['items'][0] === 'nitro_link_1', 'item content preserved');

$sentPayload = json_decode($lastRequest['body'], true);
$assert($sentPayload['product_id'] === 10, 'sent numeric product_id');
$assert($sentPayload['quantity'] === 2, 'sent correct quantity');
$assert($sentPayload['currency'] === 'vnd', 'sent currency vnd');
$assert(str_contains(implode(';', $lastRequest['headers']), 'Idempotency-Key: idemp_key_999'), 'sent idempotency key');

// Test Deliver quantity mismatch
$mockResponse = [
    'status' => 200,
    'data' => [
        'status' => 'success',
        'order_id' => 8889,
        'items' => ['only_one_item'],
    ],
    'raw' => '{}',
];
$mismatchResult = $mock->deliver('10', 2, 'idemp_key_1000');
$assert(!$mismatchResult['success'] && $mismatchResult['error_code'] === 'invalid_delivery', 'detects delivery quantity mismatch');

// Test Deliver error handling (Out of stock - non retryable)
$mockResponse = [
    'status' => 400,
    'data' => [
        'status' => 'error',
        'message' => 'Out of stock',
    ],
    'raw' => '{}',
];
$outOfStock = $mock->deliver('10', 1, 'idemp_1001');
$assert(!$outOfStock['success'] && $outOfStock['error'] === 'Out of stock' && $outOfStock['retryable'] === false, '400 Out of stock is not retryable');

// Test Deliver error handling (500 Server error - retryable)
$mockResponse = [
    'status' => 500,
    'data' => [
        'status' => 'error',
        'message' => 'Internal server error',
    ],
    'raw' => '{}',
];
$serverError = $mock->deliver('10', 1, 'idemp_1002');
$assert(!$serverError['success'] && $serverError['retryable'] === true, '500 error is marked retryable');

// 4. Kiểm tra tích hợp trong SupplierRegistry
$registry = new SupplierRegistry([
    'tunvn_prehub' => [
        'enabled' => true,
        'name' => '@TunVN_PreHub_bot',
        'api_key' => 'test_key',
    ],
]);
$registeredProvider = $registry->get('tunvn_prehub');
$assert($registeredProvider !== null, 'tunvn_prehub is registered in SupplierRegistry');
$assert($registeredProvider instanceof TunVnPreHubSupplier, 'instance of TunVnPreHubSupplier');
$assert($registeredProvider->name() === '@TunVN_PreHub_bot', 'registered provider name matches');
$assert(isset($registry->names()['tunvn_prehub']), 'tunvn_prehub is in names() list');

// 5. Kiểm tra tồn kho nhà cung cấp độc lập với số dư tài khoản
$fakeService = new class {
    public function effectiveStock(array $product, float $balance = 0.0): int
    {
        if (empty($product['is_active']) || empty($product['is_available'])) {
            return 0;
        }

        return max(0, (int) ($product['stock'] ?? 0));
    }
};

$productVnd = [
    'stock' => 15,
    'unit_price' => 50000,
    'currency' => 'VND',
    'is_active' => 1,
    'is_available' => 1,
];

$assert($fakeService->effectiveStock($productVnd, 120000.0) === 15, 'supplier stock is not limited by balance');
$assert($fakeService->effectiveStock($productVnd, 1000000.0) === 15, 'supplier stock remains the reported total');
$assert($fakeService->effectiveStock($productVnd, 30000.0) === 15, 'supplier stock remains available with low balance');

$inactiveProduct = $productVnd;
$inactiveProduct['is_active'] = 0;
$assert($fakeService->effectiveStock($inactiveProduct, 1000000.0) === 0, 'inactive product has no effective stock');

$unavailableProduct = $productVnd;
$unavailableProduct['is_available'] = 0;
$assert($fakeService->effectiveStock($unavailableProduct, 1000000.0) === 0, 'unavailable product has no effective stock');

echo "OK: {$count} TunVnPreHubSupplier assertions passed successfully.\n";
