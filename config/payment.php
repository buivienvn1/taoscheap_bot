<?php

declare(strict_types=1);

$config = [
    'wallet_currency' => 'VND',
    'acb' => [
        'enabled' => false,
        'processing_enabled' => false,
        'activation_time' => null,
        'username' => '',
        'password' => '',
        'account_number' => '',
        'session_file' => dirname(__DIR__) . '/runtime/acb/session.json',
        'lookback_minutes' => 180,
        'initial_lookback_days' => 2,
        'page_size' => 100,
        'max_pages' => 20,
        'processing_batch_size' => 200,
        'connect_timeout' => 8,
        'request_timeout' => 20,
    ],
];

$localFile = __DIR__ . '/payment.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (!is_array($local)) {
        throw new RuntimeException('Invalid local payment configuration');
    }
    $config = array_replace_recursive($config, $local);
}

return $config;
