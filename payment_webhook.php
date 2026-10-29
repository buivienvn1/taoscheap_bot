<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$providedSecret = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
$providedSecret = preg_replace('/^Bearer\s+/i', '', $providedSecret) ?? '';
if (!hash_equals((string) $app['payment_secret'], trim($providedSecret))) {
    http_response_code(403);
    exit('Forbidden');
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('Invalid payload');
}

$eventId = trim((string) ($payload['id'] ?? $payload['referenceCode'] ?? ''));
$isIncoming = !isset($payload['transferType']) || strtolower((string) $payload['transferType']) === 'in';
if ($eventId === '' || !$isIncoming) {
    http_response_code(422);
    exit('Ignored');
}

try {
    $outbox = new NotificationOutbox($db, $telegram);
    $processor = new PaymentProcessor($db, $outbox, $app);
    $result = $processor->process([
        'provider' => (string) $app['payment_provider'],
        'provider_event_id' => $eventId,
        'amount' => $payload['transferAmount'] ?? $payload['amount'] ?? null,
        'content' => (string) ($payload['content'] ?? $payload['description'] ?? ''),
        'direction' => 'IN',
        'transaction_at' => (string) ($payload['transactionDate'] ?? ''),
    ]);
    if ($result['outbox_id'] !== null) $outbox->deliver((int) $result['outbox_id']);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'result' => $result['result']]);
} catch (Throwable $error) {
    error_log('Payment webhook failed: ' . $error::class);
    http_response_code(500);
    echo json_encode(['success' => false]);
}
