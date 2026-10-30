<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/bootstrap.php';
$lock = fopen(sys_get_temp_dir() . '/taos-referral-rewards.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
try {
    $paid = (new ReferralService($db, $telegram))->payBatch();
    fwrite(STDOUT, json_encode(['paid' => $paid]) . PHP_EOL);
} catch (Throwable $error) {
    error_log('Referral rewards worker failed: ' . $error::class);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
