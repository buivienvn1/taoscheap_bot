<?php

declare(strict_types=1);

final class BinancePayAdapter
{
    public function adapt(array $payload, string $provider): array
    {
        if (!isset($payload['status']) || !in_array(strtolower((string) $payload['status']), ['success', 'ok'], true)) {
            throw new RuntimeException('binance_feed_status_unsafe');
        }
        if (!isset($payload['transactions']) || !is_array($payload['transactions'])) {
            throw new RuntimeException('binance_feed_transactions_invalid');
        }

        $transactions = [];
        foreach ($payload['transactions'] as $item) {
            if (!is_array($item) || strtoupper((string) ($item['type'] ?? '')) !== 'BINANCE_PAY') continue;
            $meta = json_decode((string) ($item['currency'] ?? ''), true);
            if (!is_array($meta)) continue;
            $orderId = trim((string) ($meta['orderId'] ?? ''));
            $status = strtoupper(trim((string) ($meta['status'] ?? '')));
            $amount = PaymentProcessor::normalizeAmount($item['amount'] ?? null, 4);
            $transactionId = trim((string) ($item['transactionID'] ?? $item['transactionNumber'] ?? ''));
            $date = trim((string) ($item['transactionDate'] ?? ''));
            if ($orderId === '' || preg_match('/^[0-9]{8,64}$/', $orderId) !== 1 || $status !== 'SUCCESS'
                || $amount === null || (float) $amount <= 0 || $transactionId === '' || $date === '') continue;
            $transactions[] = [
                'provider' => $provider,
                'order_id' => $orderId,
                'status' => $status,
                'transaction_id' => $transactionId,
                'transaction_number' => (string) ($item['transactionNumber'] ?? ''),
                'amount_usdt' => $amount,
                'transaction_at' => $date,
                'raw' => $item,
            ];
        }

        return [
            'stale' => (bool) ($payload['stale'] ?? false),
            'incomplete' => (bool) ($payload['incomplete'] ?? false),
            'transactions' => $transactions,
        ];
    }
}
