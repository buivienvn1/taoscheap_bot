<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/bootstrap.php';

$lock = fopen(sys_get_temp_dir() . '/taos-supplier-inventory.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

try {
    $result = $supplierCatalog->refreshInventory();
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($result['errors'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    error_log('Supplier inventory worker failed: ' . $error::class . ': ' . $error->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
