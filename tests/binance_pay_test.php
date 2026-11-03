<?php

declare(strict_types=1);

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

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/019_binance_pending_claims.sql') ?: '';
$service = file_get_contents($root . '/core/BinancePayService.php') ?: '';
$router = file_get_contents($root . '/bot/Router.php') ?: '';
$cron = file_get_contents($root . '/cron/payment_feed.php') ?: '';
$vi = require $root . '/languages/vi.php';
$en = require $root . '/languages/en.php';

$assert(str_contains($migration, 'UNIQUE KEY uq_binance_pending_order_id'), 'pending claims enforce unique Binance Order ID');
$assert(str_contains($migration, "ENUM('pending','processing','claimed','expired','failed','cancelled')"), 'pending claims define durable state machine');
$assert(str_contains($service, "WHERE id=? AND status='pending' AND expires_at>NOW()"), 'claim worker uses atomic pending transition');
$assert(str_contains($service, "status='processing' AND last_checked_at<DATE_SUB(NOW(), INTERVAL 2 MINUTE)"), 'stuck processing claims are recoverable');
$assert(str_contains($service, 'DATE_ADD(NOW(), INTERVAL 30 MINUTE)'), 'pending claims expire after 30 minutes');
$assert(str_contains($router, "sleep(4)"), 'fast path polls at four-second intervals');
$assert(str_contains($router, "['binance_checking_step_1', 'binance_checking_step_2', 'binance_checking_step_3', 'binance_checking_step_4']"), 'fast path renders four progress stages');
$assert(str_contains($router, 'deleteMessage($chatId, $inputMessageId)'), 'submitted Order ID message is cleaned up');
$assert(str_contains($router, 'recordBinanceProgressMessage'), 'progress message is registered for idempotent cleanup');
$assert(str_contains($cron, '$binancePay->processPendingClaims(100)'), 'payment cron processes delayed Binance claims');
$assert(str_contains($cron, '$outbox->retryBatch(100)'), 'payment cron delivers queued claim notifications');
$assert(array_diff_key($vi, $en) === [] && array_diff_key($en, $vi) === [], 'Vietnamese and English language keys stay in sync');
foreach (['binance_checking_step_1', 'binance_checking_step_2', 'binance_checking_step_3', 'binance_checking_step_4', 'binance_claim_queued', 'binance_claim_expired'] as $key) {
    $assert(isset($vi[$key], $en[$key]), 'both languages define ' . $key);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK\n";
