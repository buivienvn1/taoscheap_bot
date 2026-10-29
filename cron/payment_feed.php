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
    $feed = (new PaymentFeedAdapter())->adapt($client->fetchPage(), (string) ($config['provider'] ?? 'acb_third_party'));
    $outbox = new NotificationOutbox($db, $telegram);
    $processor = new PaymentProcessor($db, $outbox, $app);
    $result = (new PaymentFeedService($db, $processor, $config))->run($feed);
    fwrite(STDOUT, json_encode($result) . PHP_EOL);
} catch (Throwable $error) {
    error_log('Payment feed failed: ' . $error::class);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
