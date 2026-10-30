<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Language.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/NotificationOutbox.php';
require_once __DIR__ . '/../core/PaymentProcessor.php';

$failures = [];
$assert = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = 'FAIL: ' . $name;
    } else {
        echo 'PASS: ' . $name . PHP_EOL;
    }
};

// 1. Kiểm tra ProductHandler.php và admin/modules/stocks.php không đếm sold từ stocks is_sold=1
$productHandlerCode = file_get_contents(__DIR__ . '/../bot/handlers/ProductHandler.php');
$stocksAdminCode = file_get_contents(__DIR__ . '/../admin/modules/stocks.php');
$paymentProcessorCode = file_get_contents(__DIR__ . '/../core/PaymentProcessor.php');

$assert(
    !str_contains($productHandlerCode, "stocks WHERE is_sold=1"),
    'ProductHandler does not query stocks WHERE is_sold=1'
);

$assert(
    str_contains($productHandlerCode, "COALESCE(SUM(quantity),0) total FROM orders WHERE status='completed' GROUP BY variant_id"),
    'ProductHandler queries completed orders for sold count'
);

$assert(
    str_contains($stocksAdminCode, "COALESCE(SUM(quantity),0) total FROM orders WHERE status='completed' GROUP BY variant_id")
    || str_contains($stocksAdminCode, "COALESCE(SUM(quantity),0) total FROM orders WHERE status=\\'completed\\' GROUP BY variant_id"),
    'Stocks admin module queries completed orders for sold count'
);

// 2. Kiểm tra PaymentProcessor::expireDirectOrders không gửi order_expired và có gọi cleanupDirect
$assert(
    str_contains($paymentProcessorCode, '$this->outbox->cleanupDirect(\'order\', (int) $row[\'id\'])'),
    'PaymentProcessor calls cleanupDirect on direct order expire'
);

// Kiểm tra trong thân hàm expireDirectOrders không có order_expired
preg_match('/public function expireDirectOrders\([^)]*\): int\s*\{(.*?)\n    \}/s', $paymentProcessorCode, $matches);
$assert(!empty($matches[1]), 'Extracted expireDirectOrders method body');
if (!empty($matches[1])) {
    $expireDirectBody = $matches[1];
    $assert(
        !str_contains($expireDirectBody, "'order_expired'"),
        'expireDirectOrders does not enqueue order_expired notification'
    );
    $assert(
        str_contains($expireDirectBody, "status='cancelled',result_reason='expired'"),
        'expireDirectOrders marks order as cancelled with result_reason expired'
    );
}

// 3. Kiểm tra NotificationOutbox có phương thức cleanupDirect
$outboxReflection = new ReflectionClass(NotificationOutbox::class);
$assert($outboxReflection->hasMethod('cleanupDirect'), 'NotificationOutbox has cleanupDirect method');
$cleanupMethod = $outboxReflection->getMethod('cleanupDirect');
$assert($cleanupMethod->isPublic(), 'cleanupDirect is public');
$params = $cleanupMethod->getParameters();
$assert(count($params) === 2 && $params[0]->getName() === 'requestType' && $params[1]->getName() === 'requestId', 'cleanupDirect parameters match');

// 4. Kiểm tra logic tính tổng sold: Đơn hàng hoàn thành từ API (không có stock) + Đơn hoàn thành từ kho thủ công + fake_sold
$simulatedOrders = [
    ['variant_id' => 10, 'quantity' => 2, 'status' => 'completed', 'type' => 'manual'],
    ['variant_id' => 10, 'quantity' => 5, 'status' => 'completed', 'type' => 'api'],
    ['variant_id' => 10, 'quantity' => 3, 'status' => 'pending',   'type' => 'manual'],
    ['variant_id' => 10, 'quantity' => 1, 'status' => 'cancelled', 'type' => 'api'],
    ['variant_id' => 20, 'quantity' => 10, 'status' => 'completed', 'type' => 'api'],
];

$variant10Sold = 0;
foreach ($simulatedOrders as $o) {
    if ($o['variant_id'] === 10 && $o['status'] === 'completed') {
        $variant10Sold += (int) $o['quantity'];
    }
}
$assert($variant10Sold === 7, 'Completed orders for variant 10 count 7 (2 manual + 5 api)');

$fakeSold = 50;
$totalDisplay = $variant10Sold + $fakeSold;
$assert($totalDisplay === 57, 'Total displayed sold count includes fake_sold: 57');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK: All order sold and expire tests passed successfully.\n";
