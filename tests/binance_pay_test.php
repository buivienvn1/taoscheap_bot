<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Helpers.php';
require dirname(__DIR__) . '/core/PaymentProcessor.php';
require dirname(__DIR__) . '/core/BinancePayAdapter.php';

$failures = [];
$assert = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = $name;
    }
};

$adapter = new BinancePayAdapter();
$feed = $adapter->adapt([
    'status' => 'success',
    'transactions' => [
        [
            'transactionID' => 'TX-BINANCE-001',
            'transactionNumber' => 'BN-001',
            'amount' => '12.3456',
            'transactionDate' => '2026-01-01T00:00:00+07:00',
            'type' => 'BINANCE_PAY',
            'currency' => json_encode([
                'orderId' => '1234567890123456',
                'status' => 'SUCCESS',
            ], JSON_THROW_ON_ERROR),
        ],
        [
            'transactionID' => 'TX-BINANCE-002',
            'transactionNumber' => 'BN-002',
            'amount' => '1.00',
            'transactionDate' => '2026-01-01T00:00:00+07:00',
            'type' => 'BINANCE_PAY',
            'currency' => '{"orderId":"1234567890123457","status":"PENDING"}',
        ],
        [
            'transactionID' => 'TX-BINANCE-003',
            'transactionNumber' => 'BN-003',
            'amount' => '1.00',
            'transactionDate' => '2026-01-01T00:00:00+07:00',
            'type' => 'TRANSFER',
            'currency' => '{"orderId":"1234567890123458","status":"SUCCESS"}',
        ],
    ],
    'stale' => false,
    'incomplete' => false,
], 'test-binance');

$assert($feed['stale'] === false && $feed['incomplete'] === false, 'safe Binance feed flags');
$assert(count($feed['transactions']) === 1, 'only successful Binance Pay transaction is accepted');
if (count($feed['transactions']) === 1) {
    $transaction = $feed['transactions'][0];
    $assert($transaction['order_id'] === '1234567890123456', 'extract Binance order ID from currency JSON');
    $assert($transaction['status'] === 'SUCCESS', 'accept SUCCESS transaction status');
    $assert($transaction['amount_usdt'] === '12.3456', 'preserve supported USDT precision');
    $assert($transaction['transaction_id'] === 'TX-BINANCE-001', 'extract transaction ID');
}
$assert(PaymentProcessor::normalizeAmount('12.3456', 4) === '12.3456', 'normalize USDT precision');
$assert(PaymentProcessor::normalizeAmount('12.34567890', 4) === null, 'reject excessive USDT precision');
$assert(Helpers::formatDecimal('2.00000000', 8) === '2', 'remove trailing USDT zeroes');
$assert(Helpers::formatDecimal('1.96080000', 8) === '1.9608', 'preserve meaningful USDT decimals');
$assert(Helpers::formatDecimal('100', 0) === '100', 'zero precision does not trim integer zeroes');
$assert(Helpers::cleanNumber('10.00000000', 8) === '10', 'clean raw decimal amount');
$assert(Helpers::formatDualPrice('50000', '25500', 'bank') === '50.000 VND (1.96 $)', 'bank preference prioritizes VND');
$assert(Helpers::formatDualPrice('50000', '25500', 'binance_pay') === '1.96 $ (50.000 VND)', 'Binance preference prioritizes dollar equivalent');
$assert(Helpers::formatDualPrice('255000', '25500', 'bank') === '255.000 VND (10 $)', 'whole dollar equivalent omits trailing zeroes');
$assert(Helpers::formatDualPrice('50000', '25500', 'bank', true) === '50.000 VND (<code>1.96 $</code>)', 'product list wraps only the secondary dollar price in code');
$assert(Helpers::formatDualPrice('50000', '25500', 'binance_pay', true) === '1.96 $ (<code>50.000 VND</code>)', 'product list wraps only the secondary VND price in code');
$assert(Helpers::formatDualPrice('50000', '0', 'binance_pay', true) === '50.000 VND', 'missing rate leaves the only available price unwrapped');
$assert(Helpers::formatDualPrice('-50000', '25500', 'bank') === '-50.000 VND (-1.96 $)', 'negative Admin amount keeps the sign in both currencies');
$assert(Helpers::formatDualPrice('-50000', '25500', 'binance_pay') === '-1.96 $ (-50.000 VND)', 'negative customer dollar-first amount keeps the sign in both currencies');
$roundedUsdt = ceil((19900 / 25500) * 10000) / 10000;
$assert($roundedUsdt * 25500 >= 19900, 'ceil USDT conversion never underpays VND amount');

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/019_binance_pending_claims.sql') ?: '';
$service = file_get_contents($root . '/core/BinancePayService.php') ?: '';
$router = file_get_contents($root . '/bot/Router.php') ?: '';
$orderHandler = file_get_contents($root . '/bot/handlers/OrderHandler.php') ?: '';
$productHandler = file_get_contents($root . '/bot/handlers/ProductHandler.php') ?: '';
$processor = file_get_contents($root . '/core/PaymentProcessor.php') ?: '';
$moneyFormatter = file_get_contents($root . '/core/MoneyFormatter.php') ?: '';
$adminOrders = file_get_contents($root . '/admin/modules/orders.php') ?: '';
$cron = file_get_contents($root . '/cron/payment_feed.php') ?: '';
$vi = require $root . '/languages/vi.php';
$en = require $root . '/languages/en.php';

$assert(str_contains($migration, 'UNIQUE KEY uq_binance_pending_order_id'), 'pending claims enforce unique Binance Order ID');
$assert(str_contains($migration, "ENUM('pending','processing','claimed','expired','failed','cancelled')"), 'pending claims define durable state machine');
$assert(str_contains($service, "WHERE id=? AND status='pending' AND expires_at>NOW()"), 'claim worker uses atomic pending transition');
$assert(str_contains($service, "status='processing' AND last_checked_at<DATE_SUB(NOW(), INTERVAL 2 MINUTE)"), 'stuck processing claims are recoverable');
$assert(str_contains($service, 'DATE_ADD(NOW(), INTERVAL 30 MINUTE)'), 'pending claims expire after 30 minutes');
$assert(str_contains($router, "'binance_verifying'"), 'fast path renders one stable verification message');
$assert(!str_contains($router, 'sleep(5)'), 'fast path no longer uses artificial polling delays');
$assert(str_contains($router, "['not_found', 'processing', 'processing_failed']"), 'recoverable claims keep the stable verification message');
$assert(str_contains($router, 'deleteMessage($chatId, $inputMessageId)'), 'submitted Order ID message is cleaned up');
$assert(str_contains($router, 'recordBinanceProgressMessage'), 'verification message is registered for final outbox edit');
$assert((bool) preg_match('/use\s*\([^)]*\$requestToken\s*,\s*\$paymentMethod\s*\)/s', $orderHandler), 'pending order transaction captures the selected payment method');
$assert(str_contains($productHandler, 'Helpers::formatDualPrice($product[\'price\'], $rate, $preference, true)'), 'product list enables a copyable secondary price');
$assert(str_contains($orderHandler, 'u.preferred_payment_method'), 'wallet delivery loads the latest user price preference');
$assert(str_contains($orderHandler, '$this->money->format('), 'wallet delivery uses the shared money formatter');
$assert(substr_count($processor, 'u.preferred_payment_method') >= 3, 'external payment delivery loads the latest user price preference in every order path');
$assert(str_contains($processor, '$this->money->format($total'), 'external payment delivery uses the shared money formatter');
$assert(str_contains($moneyFormatter, "'bank'"), 'Admin formatter always requests VND-first display');
$assert(str_contains($adminOrders, '$moneyFormatter->admin('), 'Admin order amounts use the shared VND-first formatter');
$assert(str_contains($orderHandler, "'amount' => Helpers::money(\$bankAmount)"), 'Binance transfer keeps exact USDT primary with a VND-only annotation');
$assert(str_contains($vi['binance_order_amount_line'] ?? '', '<code>:usdt USDT</code>'), 'Vietnamese Binance amount is copyable');
$assert(str_contains($en['binance_order_amount_line'] ?? '', '<code>:usdt USDT</code>'), 'English Binance amount is copyable');
$assert(str_contains($vi['product_item_sub'] ?? '', '<code>:count</code>'), 'Vietnamese product stock is copyable');
$assert(str_contains($en['product_item_sub'] ?? '', '<code>:count</code>'), 'English product stock is copyable');
$assert(str_contains($cron, '$binancePay->processPendingClaims(100)'), 'payment cron processes delayed Binance claims');
$assert(str_contains($cron, '$outbox->retryBatch(100)'), 'payment cron delivers queued claim notifications');
$assert(array_diff_key($vi, $en) === [] && array_diff_key($en, $vi) === [], 'Vietnamese and English language keys stay in sync');
foreach (['binance_verifying', 'binance_claim_expired', 'deposit_binance_success', 'admin_deposit_binance_success', 'back_product_detail', 'product_item_sub'] as $key) {
    $assert(isset($vi[$key], $en[$key]), 'both languages define ' . $key);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK\n";
