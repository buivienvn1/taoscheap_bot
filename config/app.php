<?php

declare(strict_types=1);

return [
    'app_url' => 'https://example.com',
    'timezone' => 'Asia/Ho_Chi_Minh',
    'bot_token' => 'PUT_TELEGRAM_BOT_TOKEN_HERE',
    'telegram_secret' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',
    'payment_provider' => 'sepay',
    'payment_secret' => 'CHANGE_PAYMENT_WEBHOOK_SECRET',
    // Telegram IDs nhận thông báo giao dịch. Mỗi admin cần mở bot ít nhất một lần.
    'admin_telegram_ids' => [],
    'admin_username' => 'admin',
    'admin_password_hash' => '',
    // Số ngày duy trì phiên đăng nhập admin, kể cả khi đóng trình duyệt.
    'admin_session_days' => 7,
];
