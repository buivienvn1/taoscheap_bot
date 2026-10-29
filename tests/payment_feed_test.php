<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/Helpers.php';
require dirname(__DIR__) . '/core/PaymentCodeRegistry.php';
require dirname(__DIR__) . '/core/PaymentProcessor.php';
require dirname(__DIR__) . '/core/PaymentFeedAdapter.php';

$failures = [];
$assert = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) $failures[] = $name;
};
$assert(PaymentCodeRegistry::extractCodes('CK DONATEABC234 done') === ['DONATEABC234'], 'extract surrounded code');
$assert(PaymentCodeRegistry::extractCodes('XDONATEABC234') === [], 'reject longer prefix');
$assert(PaymentCodeRegistry::extractCodes('DONATEABC2345') === [], 'reject longer suffix');
$assert(count(PaymentCodeRegistry::extractCodes('DONATEABC234 DONATEXYZ678')) === 2, 'detect multiple codes');
$assert(PaymentProcessor::normalizeAmount('1000') === '1000.00', 'integer amount');
$assert(PaymentProcessor::normalizeAmount('1000.25') === '1000.25', 'decimal amount');
$assert(PaymentProcessor::normalizeAmount(10.5) === '10.50', 'normalize webhook float amount');
$adapter = new PaymentFeedAdapter();
$feed = $adapter->adapt(['status' => 'success', 'transactions' => [['transactionID' => '1', 'transactionNumber' => '2', 'amount' => '1000', 'description' => 'DONATEABC234', 'transactionDate' => '2026-01-01T00:00:00+07:00', 'type' => 'IN']], 'next_cursor' => 'opaque', 'stale' => false, 'incomplete' => false, 'last_sync_at' => null, 'last_sync_ok_at' => null], 'test');
$assert($feed['safe'] && count($feed['transactions']) === 1, 'adapt safe feed');
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "OK\n";
