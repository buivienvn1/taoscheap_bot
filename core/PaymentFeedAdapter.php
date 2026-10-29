<?php

declare(strict_types=1);

final class PaymentFeedAdapter
{
    public function adapt(array $payload, string $provider): array
    {
        if (!array_key_exists('status', $payload) || !self::successfulStatus($payload['status'])) throw new RuntimeException('feed_status_unsafe');
        if (!isset($payload['transactions']) || !is_array($payload['transactions'])) throw new RuntimeException('feed_transactions_invalid');
        if (!isset($payload['stale'], $payload['incomplete']) || !is_bool($payload['stale']) || !is_bool($payload['incomplete'])) throw new RuntimeException('feed_flags_invalid');
        $safe = !$payload['stale'] && !$payload['incomplete'];
        $transactions = [];
        foreach ($payload['transactions'] as $item) {
            if (!is_array($item)) continue;
            $amount = PaymentProcessor::normalizeAmount($item['amount'] ?? null);
            $description = $item['description'] ?? null;
            $type = $item['type'] ?? null;
            $date = $item['transactionDate'] ?? null;
            if (!is_string($description) || !is_string($type) || !is_string($date) || $amount === null) continue;
            $transactions[] = [
                'provider' => $provider,
                'provider_event_id' => (string) ($item['transactionID'] ?? $item['transactionNumber'] ?? ''),
                'provider_transaction_id' => (string) ($item['transactionID'] ?? ''),
                'provider_transaction_number' => (string) ($item['transactionNumber'] ?? ''),
                'amount' => $amount,
                'content' => $description,
                'direction' => strtoupper($type),
                'transaction_at' => $date,
            ];
        }
        return [
            'safe' => $safe,
            'stale' => $payload['stale'],
            'incomplete' => $payload['incomplete'],
            'last_sync_at' => is_string($payload['last_sync_at'] ?? null) ? $payload['last_sync_at'] : null,
            'last_sync_ok_at' => is_string($payload['last_sync_ok_at'] ?? null) ? $payload['last_sync_ok_at'] : null,
            'transactions' => $transactions,
            'next_cursor' => is_string($payload['next_cursor'] ?? null) ? $payload['next_cursor'] : null,
        ];
    }

    private static function successfulStatus(mixed $status): bool
    {
        return $status === true || $status === 1 || (is_string($status) && in_array(strtolower($status), ['ok', 'success'], true));
    }
}
