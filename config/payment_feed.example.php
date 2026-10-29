<?php

declare(strict_types=1);

return [
    'enabled' => false,
    'processing' => false,
    'provider' => 'acb_third_party',
    'url' => 'https://feed-provider.example/api/transactions',
    'token' => 'REPLACE_WITH_ROTATED_TOKEN',
    'token_header' => 'Authorization',
    'token_prefix' => 'Bearer ',
    'allowed_host' => 'feed-provider.example',
    'timeout_seconds' => 15,
    'max_response_bytes' => 1048576,
    'activation_at' => '',
    // Cursor is deliberately disabled until the provider documents its request contract.
    'cursor_parameter' => '',
];
