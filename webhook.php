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
    (new Router($db, $telegram, $vietQr, $app, $supplierCatalog))->handle($update);
} catch (Throwable $error) {
    error_log(sprintf(
        'Telegram webhook error [%s] %s in %s:%d%s%s',
        $error::class,
        $error->getMessage(),
        $error->getFile(),
        $error->getLine(),
        PHP_EOL,
        $error->getTraceAsString()
    ));
}

// Acknowledge valid Telegram updates even when application handling fails.
// Returning 5xx makes Telegram retry the same update and can block newer callbacks.
http_response_code(200);
echo 'OK';
