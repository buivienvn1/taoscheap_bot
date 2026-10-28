<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($app['telegram_secret'], $secret)) {
    http_response_code(403);
    exit('Forbidden');
}

$update = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($update)) {
    http_response_code(400);
    exit('Invalid update');
}

try {
    (new Router($db, $telegram, $vietQr, $app))->handle($update);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(500);
    echo 'Error';
}
