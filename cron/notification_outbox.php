<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/taos-notification-outbox.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try {
    $sent = (new NotificationOutbox($db, $telegram))->retryBatch(20);
    fwrite(STDOUT, json_encode(['sent' => $sent]) . PHP_EOL);
} catch (Throwable $error) {
    error_log('Notification outbox worker failed: ' . $error::class);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
