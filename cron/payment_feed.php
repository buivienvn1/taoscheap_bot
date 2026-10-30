<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/bootstrap.php';
$configFile = dirname(__DIR__) . '/config/payment_feed.local.php';
if (!is_file($configFile)) exit(0);
$config = require $configFile;
if (!is_array($config) || !($config['enabled'] ?? false)) exit(0);

$lock = fopen(sys_get_temp_dir() . '/taos-payment-feed.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try {
    $client = new PaymentFeedClient($config);
    $adapter = new PaymentFeedAdapter();
    $outbox = new NotificationOutbox($db, $telegram);
    $processor = new PaymentProcessor($db, $outbox, $app, $stockFulfillment);
    $service = new PaymentFeedService($db, $processor, $config);
    $provider = (string) ($config['provider'] ?? 'acb_third_party');
    $startedAt = time();

    do {
        try {
            $result = [
                'hybrid_orders_expired' => $processor->expireHybridOrders(100),
                'direct_orders_expired' => $processor->expireDirectOrders(100),
            ];
            $feed = $adapter->adapt($client->fetchPage(), $provider);
            $result += $service->run($feed);
            $result['notifications_sent'] = $outbox->retryBatch(100);
            fwrite(STDOUT, json_encode($result) . PHP_EOL);
        } catch (Throwable $error) {
            error_log('Payment feed failed: ' . $error::class);
        }

        $remainingSeconds = 50 - (time() - $startedAt);
        if ($remainingSeconds < 10) break;
        sleep(10);
    } while (true);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
