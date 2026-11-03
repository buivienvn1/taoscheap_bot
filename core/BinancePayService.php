<?php

declare(strict_types=1);

final class BinancePayService
{
    public function __construct(
        private Database $db,
        private BinancePayClient $client,
        private BinancePayAdapter $adapter,
        private PaymentProcessor $processor,
        private array $config = []
    ) {
    }

    public function isEnabled(): bool
    {
        return (string) ($this->setting('binance_pay_enabled') ?? '0') === '1'
            && trim((string) ($this->setting('binance_pay_id') ?? '')) !== '';
    }

    public function paymentDetails(): array
    {
        return [
            'id' => trim((string) ($this->setting('binance_pay_id') ?? '')),
            'rate' => $this->rate(),
        ];
    }

    public function claimOrderId(int $userId, string $orderId): array
    {
        $orderId = trim($orderId);
        if (preg_match('/^[0-9]{8,64}$/', $orderId) !== 1) {
            return ['result' => 'invalid_order_id'];
        }
        if (!$this->isEnabled()) return ['result' => 'disabled'];

        $transaction = $this->db->fetch('SELECT * FROM binance_pay_transactions WHERE binance_order_id=?', [$orderId]);
        if ($transaction === null) {
            $this->sync();
            $transaction = $this->db->fetch('SELECT * FROM binance_pay_transactions WHERE binance_order_id=?', [$orderId]);
        }
        if ($transaction === null) return ['result' => 'not_found'];
        if ($transaction['claimed_at'] !== null) return ['result' => 'already_claimed'];

        $request = $this->db->fetch(
            "SELECT 'order' request_type,id,amount,payment_code FROM orders
             WHERE user_id=? AND payment_method='binance_pay' AND status='pending' AND binance_order_id IS NULL
             ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        if ($request === null) {
            $request = $this->db->fetch(
                "SELECT 'deposit' request_type,id,0.00 amount,code payment_code FROM deposits
                 WHERE user_id=? AND payment_method='binance_pay' AND status='pending' AND binance_order_id IS NULL
                 ORDER BY id DESC LIMIT 1",
                [$userId]
            );
        }
        if ($request === null) return ['result' => 'no_pending_request'];

        $amountVnd = number_format((float) $transaction['amount_usdt'] * $this->rate(), 2, '.', '');
        $minimum = (float) $request['amount'];
        if ($request['request_type'] === 'order' && (float) $amountVnd < $minimum) {
            return [
                'result' => 'short_payment',
                'amount_usdt' => (string) $transaction['amount_usdt'],
                'required_vnd' => (string) $request['amount'],
            ];
        }

        $payment = [
            'provider' => (string) ($this->config['provider'] ?? 'binance_pay_third_party'),
            'provider_event_id' => (string) $transaction['transaction_id'],
            'provider_transaction_id' => (string) $transaction['transaction_id'],
            'provider_transaction_number' => (string) ($transaction['transaction_number'] ?? ''),
            'amount' => $amountVnd,
            'content' => (string) $request['payment_code'],
            'direction' => 'IN',
            'transaction_at' => (string) ($transaction['transaction_at'] ?? date('c')),
        ];
        $result = $this->processor->process($payment);
        $processorResult = (string) ($result['result'] ?? '');
        if ($processorResult === 'duplicate_code') return ['result' => 'already_claimed'];
        if (!$this->isDefinitiveProcessorResult($processorResult)) {
            return ['result' => 'processing_failed'];
        }

        $claimed = $this->db->transaction(function (Database $db) use ($transaction, $userId, $request): bool {
            $updated = $db->execute(
                "UPDATE binance_pay_transactions SET claimed_by_user_id=?,claimed_request_type=?,claimed_request_id=?,claimed_at=NOW() WHERE id=? AND claimed_at IS NULL",
                [$userId, $request['request_type'], $request['id'], $transaction['id']]
            );
            if ($updated !== 1) return false;
            if ($request['request_type'] === 'order') {
                $db->execute('UPDATE orders SET binance_order_id=?,binance_usdt_amount=? WHERE id=?', [$transaction['binance_order_id'], $transaction['amount_usdt'], $request['id']]);
            } else {
                $db->execute('UPDATE deposits SET binance_order_id=?,binance_usdt_amount=? WHERE id=?', [$transaction['binance_order_id'], $transaction['amount_usdt'], $request['id']]);
            }
            return true;
        });
        if (!$claimed) return ['result' => 'already_claimed'];

        return $result + [
            'result' => 'claimed',
            'order_id' => (string) $transaction['binance_order_id'],
            'amount_usdt' => (string) $transaction['amount_usdt'],
            'amount_vnd' => $amountVnd,
        ];
    }

    private function isDefinitiveProcessorResult(string $result): bool
    {
        return in_array($result, [
            'deposit_completed',
            'order_delivered',
            'closed_order_wallet',
        ], true) || str_ends_with($result, '_wallet');
    }

    private function sync(): void
    {
        $feed = $this->adapter->adapt($this->client->fetchPage(), (string) ($this->config['provider'] ?? 'binance_pay_third_party'));
        if ($feed['stale'] || $feed['incomplete']) return;
        foreach ($feed['transactions'] as $transaction) {
            $this->db->execute(
                "INSERT INTO binance_pay_transactions(transaction_id,transaction_number,binance_order_id,status,amount_usdt,transaction_at,raw_payload)
                 VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE transaction_number=VALUES(transaction_number),status=VALUES(status),amount_usdt=VALUES(amount_usdt),transaction_at=VALUES(transaction_at),raw_payload=VALUES(raw_payload)",
                [
                    $transaction['transaction_id'],
                    $transaction['transaction_number'],
                    $transaction['order_id'],
                    $transaction['status'],
                    $transaction['amount_usdt'],
                    $transaction['transaction_at'],
                    json_encode($transaction['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private function rate(): float
    {
        $rate = (float) ($this->setting('binance_pay_rate_usdt_vnd') ?? 0);
        return $rate > 0 ? $rate : 25500.0;
    }

    private function setting(string $key): ?string
    {
        $row = $this->db->fetch('SELECT key_value FROM settings WHERE key_name=?', [$key]);
        return $row === null ? null : (string) $row['key_value'];
    }
}
