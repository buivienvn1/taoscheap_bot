<?php

declare(strict_types=1);

final class PaymentProcessor
{
    public function __construct(private Database $db, private NotificationOutbox $outbox, private array $app)
    {
    }

    public function process(array $payment): array
    {
        $amount = self::normalizeAmount($payment['amount'] ?? null);
        $content = (string) ($payment['content'] ?? '');
        $codes = PaymentCodeRegistry::extractCodes($content);
        if (($payment['direction'] ?? '') !== 'IN' || $amount === null) return ['result' => 'invalid', 'outbox_id' => null];
        if (count($codes) !== 1) return ['result' => count($codes) > 1 ? 'multiple_codes' : 'unmatched', 'outbox_id' => null];
        $code = $codes[0];

        return $this->db->transaction(function (Database $db) use ($payment, $amount, $code): array {
            $registry = $db->fetch('SELECT * FROM payment_code_registry WHERE code=? FOR UPDATE', [$code]);
            if ($registry === null) return ['result' => 'unknown_code', 'outbox_id' => null];
            if ($registry['status'] === 'processed') return ['result' => 'duplicate_code', 'outbox_id' => null];
            if ($registry['status'] !== 'reserved' || $registry['request_id'] === null) return ['result' => 'review', 'outbox_id' => null];

            $eventId = $this->auditEvent($payment, $amount, $code);
            $result = $registry['request_type'] === 'deposit'
                ? $this->processDeposit((int) $registry['request_id'], $amount, $code)
                : $this->processOrder((int) $registry['request_id'], $amount, $code);
            if ($result['final']) {
                $db->execute(
                    "UPDATE payment_code_registry SET status='processed',processed_amount=?,result_type=?,processed_event_id=?,processed_at=NOW() WHERE id=?",
                    [$amount, $result['result'], $eventId, $registry['id']]
                );
            }
            if ($eventId !== null) {
                $db->execute(
                    'UPDATE payment_events SET order_id=?,deposit_id=?,result_type=?,purchase_amount=?,wallet_amount=? WHERE id=?',
                    [$result['order_id'], $result['deposit_id'], $result['result'], $result['purchase_amount'], $result['wallet_amount'], $eventId]
                );
            }
            return ['result' => $result['result'], 'outbox_id' => $result['outbox_id']];
        });
    }

    public static function normalizeAmount(mixed $value): ?string
    {
        if (is_float($value)) {
            if (!is_finite($value) || $value <= 0) return null;
            $raw = number_format($value, 2, '.', '');
        } elseif (is_int($value) || is_string($value)) {
            $raw = trim((string) $value);
        } else {
            return null;
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $raw)) return null;
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $normalized = ltrim($whole, '0');
        if ($normalized === '') $normalized = '0';
        $normalized .= '.' . str_pad($fraction, 2, '0');
        return $normalized === '0.00' ? null : $normalized;
    }

    private function auditEvent(array $payment, string $amount, string $code): ?int
    {
        $provider = substr((string) ($payment['provider'] ?? 'unknown'), 0, 30);
        $providerId = trim((string) ($payment['provider_event_id'] ?? ''));
        if ($providerId === '') $providerId = hash('sha256', implode('|', [$code, $amount, (string) ($payment['transaction_at'] ?? '')]));
        try {
            $this->db->execute(
                'INSERT INTO payment_events(provider,provider_event_id,transfer_content,amount,payload,result_type) VALUES(?,?,?,?,?,?)',
                [$provider, substr($providerId, 0, 150), $code, $amount, null, 'received']
            );
            return $this->db->lastInsertId();
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) return null;
            throw $error;
        }
    }

    private function processDeposit(int $depositId, string $amount, string $code): array
    {
        $deposit = $this->db->fetch("SELECT * FROM deposits WHERE id=? FOR UPDATE", [$depositId]);
        if ($deposit === null || $deposit['status'] !== 'pending') return $this->result('review', false, null, $depositId);
        if ((float) $amount < (float) $this->app['min_deposit']) return $this->result('deposit_below_minimum', false, null, $depositId);
        $this->db->fetch('SELECT id FROM users WHERE id=? FOR UPDATE', [$deposit['user_id']]);
        $this->db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $deposit['user_id']]);
        $this->db->execute("UPDATE deposits SET amount=?,status='completed',completed_at=NOW() WHERE id=?", [$amount, $depositId]);
        $this->db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note) VALUES(?,'deposit',?,'deposit',?,?)", [$deposit['user_id'], $amount, $depositId, 'deposit_completed']);
        $outboxId = $this->outbox->enqueue((int) $deposit['user_id'], 'payment-code:' . $code, 'deposit_success', ['amount' => Helpers::money($amount)]);
        return $this->result('deposit_completed', true, null, $depositId, '0.00', $amount, $outboxId);
    }

    private function processOrder(int $orderId, string $amount, string $code): array
    {
        $order = $this->db->fetch("SELECT o.*,v.status variant_status,p.status product_status FROM orders o JOIN variants v ON v.id=o.variant_id JOIN products p ON p.id=v.product_id WHERE o.id=? FOR UPDATE", [$orderId]);
        if ($order === null || $order['status'] !== 'pending') return $this->result('review', false, $orderId);
        $this->db->fetch('SELECT id FROM users WHERE id=? FOR UPDATE', [$order['user_id']]);
        $params = ['order' => Helpers::escape((string) $order['order_code']), 'amount' => Helpers::money($amount)];
        if ((float) $amount < (float) $order['amount']) return $this->refundOrder($order, $amount, $code, 'short_payment', 'order_result_short_payment', $params);
        if (!(int) $order['variant_status'] || !(int) $order['product_status']) return $this->refundOrder($order, $amount, $code, 'product_unavailable', 'order_result_unavailable', $params);
        $quantity = max(1, (int) $order['quantity']);
        $stocks = $this->db->fetchAll('SELECT id,content FROM stocks WHERE variant_id=? AND is_sold=0 ORDER BY id LIMIT ' . $quantity . ' FOR UPDATE', [$order['variant_id']]);
        if (count($stocks) !== $quantity) return $this->refundOrder($order, $amount, $code, 'out_of_stock', 'order_result_out_of_stock', $params);
        $contents = [];
        foreach ($stocks as $stock) {
            if ($this->db->execute('UPDATE stocks SET is_sold=1,order_id=?,sold_at=NOW() WHERE id=? AND is_sold=0', [$orderId, $stock['id']]) !== 1) throw new RuntimeException('Stock allocation conflict');
            $this->db->execute('INSERT INTO order_deliveries(order_id,stock_id,content) VALUES(?,?,?)', [$orderId, $stock['id'], $stock['content']]);
            $contents[] = $stock['content'];
        }
        $extra = number_format((float) $amount - (float) $order['amount'], 2, '.', '');
        $this->db->execute("UPDATE orders SET stock_content=?,status='completed',result_reason='delivered',wallet_credited=?,paid_at=NOW(),completed_at=NOW() WHERE id=?", [implode("\n", $contents), max(0, (float) $extra), $orderId]);
        $this->db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note) VALUES(?,'purchase',?,'order',?,?)", [$order['user_id'], -((float) $order['amount']), $orderId, $order['order_code']]);
        if ((float) $extra > 0) $this->creditWallet($order, $extra, 'overpayment');
        $params['extra'] = (float) $extra > 0 ? Helpers::money($extra) : '';
        $params['deliveries'] = $contents;
        $outboxId = $this->outbox->enqueue((int) $order['user_id'], 'payment-code:' . $code, 'order_result_success', $params);
        return $this->result('order_delivered', true, $orderId, null, (string) $order['amount'], $extra, $outboxId);
    }

    private function refundOrder(array $order, string $amount, string $code, string $reason, string $messageKey, array $params): array
    {
        $this->creditWallet($order, $amount, $reason);
        $this->db->execute("UPDATE orders SET status='failed',result_reason=?,wallet_credited=?,paid_at=NOW(),completed_at=NOW() WHERE id=?", [$reason, $amount, $order['id']]);
        $outboxId = $this->outbox->enqueue((int) $order['user_id'], 'payment-code:' . $code, $messageKey, $params);
        return $this->result($reason . '_wallet', true, (int) $order['id'], null, '0.00', $amount, $outboxId);
    }

    private function creditWallet(array $order, string $amount, string $reason): void
    {
        $this->db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $order['user_id']]);
        $this->db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note) VALUES(?,'refund',?,'order',?,?)", [$order['user_id'], $amount, $order['id'], $reason]);
    }

    private function result(string $result, bool $final, ?int $orderId = null, ?int $depositId = null, string $purchase = '0.00', string $wallet = '0.00', ?int $outboxId = null): array
    {
        return compact('result', 'final', 'orderId', 'depositId', 'purchase', 'wallet', 'outboxId') + ['order_id' => $orderId, 'deposit_id' => $depositId, 'purchase_amount' => $purchase, 'wallet_amount' => $wallet, 'outbox_id' => $outboxId];
    }
}
