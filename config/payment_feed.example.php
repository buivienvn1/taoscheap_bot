<?php

declare(strict_types=1);

return [
    'enabled' => false,
    'processing' => false,
    'provider' => 'acb_third_party',
    // Never include the token in this URL. The client appends it only in memory.
    'url' => 'https://feed-provider.example/api/v1/feed',
    'token' => 'REPLACE_WITH_ROTATED_TOKEN',
    'auth_mode' => 'query',
    'token_parameter' => 'token',
    // Used only when auth_mode is "header".
    'token_header' => 'Authorization',
    'token_prefix' => 'Bearer ',
    'allowed_host' => 'feed-provider.example',
    'timeout_seconds' => 15,
    'max_response_bytes' => 1048576,
    'activation_at' => '',
    // Cursor is deliberately disabled until the provider documents its request contract.
    'cursor_parameter' => '',
];
