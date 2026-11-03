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

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK\n";
