<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    $service = new PaymentNotificationService($db, $telegram);
    $sent = $service->deliverPending(20);
    fwrite(STDOUT, "Payment notifications sent={$sent}\n");
    exit(0);
} catch (Throwable $error) {
    error_log('Payment notification worker failed: ' . $error::class);
    fwrite(STDERR, 'Payment notification worker failed: ' . $error::class . "\n");
    exit(1);
}
