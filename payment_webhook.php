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

try {
    $eventId = trim((string) ($payload['id'] ?? $payload['referenceCode'] ?? ''));
    $reference = (string) ($payload['content'] ?? $payload['description'] ?? '');
    $amount = normalizeWebhookAmount($payload['transferAmount'] ?? $payload['amount'] ?? null);
    $rawDirection = strtolower(trim((string) ($payload['transferType'] ?? 'in')));
    $direction = match ($rawDirection) {
        'in', 'incoming', 'credit' => 'incoming',
        'out', 'outgoing', 'debit' => 'outgoing',
        default => throw new InvalidArgumentException('Unsupported transfer direction'),
    };

    $destinationAccount = trim((string) (
        $payload['accountNumber']
        ?? $payload['account_number']
        ?? $bank['account_number']
        ?? 'webhook'
    ));
    $currency = strtoupper(trim((string) ($payload['currency'] ?? 'VND')));
    $occurredAt = parseWebhookDate($payload['transactionDate'] ?? $payload['transaction_date'] ?? null);

    $event = new PaymentEvent(
        provider: strtolower((string) $app['payment_provider']),
        providerEventId: $eventId,
        destinationAccount: $destinationAccount,
        amount: $amount,
        currency: $currency,
        reference: $reference,
        direction: $direction,
        occurredAt: $occurredAt,
        payload: $payload,
    );

    $notifications = new PaymentNotificationService($db, $telegram);
    $processor = new PaymentProcessor(
        $db,
        $notifications,
        'VND',
        number_format((float) $app['min_deposit'], 2, '.', '')
    );
    $outboxId = $processor->process($event);
    if ($outboxId !== null) {
        $notifications->deliver($outboxId);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Ignored']);
} catch (Throwable $error) {
    error_log('Payment webhook failed: ' . $error::class);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false]);
}

function normalizeWebhookAmount(mixed $value): string
{
    if (is_int($value)) {
        return $value > 0 ? $value . '.00' : throw new InvalidArgumentException('Invalid amount');
    }
    if (is_float($value)) {
        $value = number_format($value, 2, '.', '');
    }

    $amount = trim((string) $value);
    $amount = str_replace([',', ' '], '', $amount);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
        throw new InvalidArgumentException('Invalid amount');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    $normalized = $whole . '.' . str_pad($fraction, 2, '0');
    if ($normalized === '0.00') {
        throw new InvalidArgumentException('Invalid amount');
    }

    return $normalized;
}

function parseWebhookDate(mixed $value): ?DateTimeImmutable
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }

    try {
        return new DateTimeImmutable((string) $value);
    } catch (Throwable) {
        throw new InvalidArgumentException('Invalid transaction date');
    }
}
