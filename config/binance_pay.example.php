<?php

declare(strict_types=1);

return [
    'enabled' => false,
    'provider' => 'binance_pay_third_party',
    // Không đặt token trực tiếp trong URL; client chỉ thêm token khi request.
    'url' => 'https://feed-provider.example/api/v1/feed',
    'token' => 'REPLACE_WITH_ROTATED_TOKEN',
    'auth_mode' => 'query',
    'token_parameter' => 'token',
    'token_header' => 'Authorization',
    'token_prefix' => 'Bearer ',
    'allowed_host' => 'feed-provider.example',
    'timeout_seconds' => 15,
    'max_response_bytes' => 1048576,
];
