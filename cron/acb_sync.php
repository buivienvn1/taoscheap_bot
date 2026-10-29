<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$acb = $payment['acb'] ?? [];
if (empty($acb['enabled'])) {
    fwrite(STDOUT, "ACB sync disabled.\n");
    exit(0);
}

try {
    $syncAccount = preg_replace('/\D+/', '', (string) ($acb['account_number'] ?? '')) ?? '';
    $qrAccount = preg_replace('/\D+/', '', (string) ($bank['account_number'] ?? '')) ?? '';
    $qrBank = strtoupper(trim((string) ($bank['bank_id'] ?? '')));
    if ($syncAccount === '' || !hash_equals($syncAccount, $qrAccount) || $qrBank !== 'ACB') {
        throw new RuntimeException('ACB payment account does not match QR configuration');
    }

    $client = new AcbClient(
        (string) ($acb['username'] ?? ''),
        (string) ($acb['password'] ?? ''),
        (string) ($acb['session_file'] ?? ''),
        (int) ($acb['connect_timeout'] ?? 8),
        (int) ($acb['request_timeout'] ?? 20),
    );
    $notifications = new PaymentNotificationService($db, $telegram);
    $processor = new PaymentProcessor(
        $db,
        $notifications,
        (string) ($payment['wallet_currency'] ?? 'VND'),
        number_format((float) $app['min_deposit'], 2, '.', '')
    );
    $service = new AcbSyncService($db, $client, $processor, $acb);
    $result = $service->run();
    fwrite(
        STDOUT,
        sprintf(
            "ACB sync %s: observed=%d processed=%d review=%d\n",
            $result['status'],
            $result['observed'],
            $result['processed'],
            $result['review']
        )
    );
    exit(0);
} catch (Throwable $error) {
    error_log('ACB sync failed: ' . $error::class);
    fwrite(STDERR, 'ACB sync failed: ' . $error::class . "\n");
    exit(1);
}
