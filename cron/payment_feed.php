<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/bootstrap.php';
$configFile = dirname(__DIR__) . '/config/payment_feed.local.php';
$config = is_file($configFile) ? require $configFile : [];
$bankFeedEnabled = is_array($config) && ($config['enabled'] ?? false);
$binanceFeedEnabled = is_array($binanceConfig) && ($binanceConfig['enabled'] ?? false);
if (!$bankFeedEnabled && !$binanceFeedEnabled) exit(0);

$lock = fopen(sys_get_temp_dir() . '/taos-payment-feed.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try {
    $client = $bankFeedEnabled ? new PaymentFeedClient($config) : null;
    $adapter = $bankFeedEnabled ? new PaymentFeedAdapter() : null;
    $outbox = $binanceOutbox;
    $processor = $binanceProcessor;
    $service = $bankFeedEnabled ? new PaymentFeedService($db, $processor, $config) : null;
    $provider = (string) ($config['provider'] ?? 'acb_third_party');
    $startedAt = time();

    do {
        try {
            $result = [
                'hybrid_orders_expired' => $processor->expireHybridOrders(100),
                'direct_orders_expired' => $processor->expireDirectOrders(100),
            ];
            if ($bankFeedEnabled && $client !== null && $adapter !== null && $service !== null) {
                $feed = $adapter->adapt($client->fetchPage(), $provider);
                $result += $service->run($feed);
            }
            if ($binanceFeedEnabled) {
                $result['binance_claims'] = $binancePay->processPendingClaims(100);
            }
            $result['notifications_sent'] = $outbox->retryBatch(100);
            $result['message_cleanups'] = $outbox->retryCleanupBatch(100);
            fwrite(STDOUT, json_encode($result) . PHP_EOL);
        } catch (Throwable $error) {
            error_log('Payment feed failed: ' . $error::class . ': ' . $error->getMessage());
        }

        $remainingSeconds = 50 - (time() - $startedAt);
        if ($remainingSeconds < 10) break;
        sleep(10);
    } while (true);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
