<?php

declare(strict_types=1);

return [
    'acb' => [
        'enabled' => true,
        'processing_enabled' => false,
        'activation_time' => '2026-01-01 00:00:00',
        'username' => 'ACB_USERNAME_HERE',
        'password' => 'ACB_PASSWORD_HERE',
        'account_number' => 'ACB_ACCOUNT_NUMBER_HERE',
        'session_file' => dirname(__DIR__) . '/runtime/acb/session.json',
        'processing_batch_size' => 200,
    ],
];
