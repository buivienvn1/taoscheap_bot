<?php

declare(strict_types=1);

final class PaymentProcessor
{
    public function __construct(
        private Database $db,
        private NotificationOutbox $outbox,
        private array $app,
        private StockFulfillmentService $fulfillment
    ) {
    }

    public function process(array $payment): array
    {
        $amount = self::normalizeAmount($payment['amount'] ?? null);
        $codes = PaymentCodeRegistry::extractCodes((string) ($payment['content'] ?? ''));
        if (($payment['direction'] ?? '') !== 'IN' || $amount === null) return ['result' => 'invalid', 'outbox_id' => null];
        if (count($codes) !== 1) return ['result' => count($codes) > 1 ? 'multiple_codes' : 'unmatched', 'outbox_id' => null];
        $code = $codes[0];
        $registry = $this->db->fetch('SELECT * FROM payment_code_registry WHERE code=?', [$code]);
        if ($registry === null) return ['result' => 'unknown_code', 'outbox_id' => null];
        if ($registry['status'] === 'processed') return ['result' => 'duplicate_code', 'outbox_id' => null];
        if ($registry['status'] !== 'reserved' || $registry['request_id'] === null) return ['result' => 'review', 'outbox_id' => null];

        if ($registry['request_type'] === 'order') {
            return $this->processOrderOutsideRemoteTransaction($registry, $payment, $amount, $code);
        }

        return $this->db->transaction(function (Database $db) use ($registry, $payment, $amount, $code): array {
            $locked = $db->fetch('SELECT * FROM payment_code_registry WHERE id=? FOR UPDATE', [$registry['id']]);
            if ($locked['status'] === 'processed') return ['result' => 'duplicate_code', 'outbox_id' => null];
            $eventId = $this->auditEvent($payment, $amount, $code);
            $result = $this->processDeposit((int) $locked['request_id'], $amount, $code);
            $this->finalizePaymentRegistry($locked, $result, $amount, $eventId);
            return ['result' => $result['result'], 'outbox_id' => $result['outbox_id']];
        });
    }

    private function processOrderOutsideRemoteTransaction(array $registry, array $payment, string $amount, string $code): array
    {
        $prepared = $this->db->transaction(function (Database $db) use ($registry, $payment, $amount, $code): array {
            $locked = $db->fetch('SELECT * FROM payment_code_registry WHERE id=? FOR UPDATE', [$registry['id']]);
            if ($locked['status'] === 'processed') return ['closed' => ['result' => 'duplicate_code', 'outbox_id' => null]];
            $order = $db->fetch("SELECT o.*,v.status variant_status,p.status product_status,u.telegram_id,u.username,u.first_name,u.last_name,u.language FROM orders o JOIN variants v ON v.id=o.variant_id JOIN products p ON p.id=v.product_id JOIN users u ON u.id=o.user_id WHERE o.id=? FOR UPDATE", [$locked['request_id']]);
            if ($order === null) return ['closed' => ['result' => 'review', 'outbox_id' => null]];
            $eventId = $this->auditEvent($payment, $amount, $code);
            if ($order['status'] === 'processing') {
                return compact('locked', 'order', 'eventId');
            }
            if ($order['status'] !== 'pending' || (float) $amount < (float) $order['amount'] || !(int) $order['variant_status'] || !(int) $order['product_status']) {
                $result = $this->processOrder((int) $order['id'], $amount, $code);
                $this->finalizePaymentRegistry($locked, $result, $amount, $eventId);
                return ['closed' => ['result' => $result['result'], 'outbox_id' => $result['outbox_id']]];
            }
            $db->execute("UPDATE orders SET status='processing',paid_at=NOW() WHERE id=? AND status='pending'", [$order['id']]);
            $order['status'] = 'processing';
            return compact('locked', 'order', 'eventId');
        });
        if (isset($prepared['closed'])) return $prepared['closed'];

        $order = $prepared['order'];
        $delivery = $this->fulfillment->fulfill((int) $order['id'], (int) $order['variant_id'], max(1, (int) $order['quantity']), (string) $order['order_code']);
        if ($delivery['pending'] ?? false) return ['result' => 'fulfillment_pending', 'outbox_id' => null];

        return $this->db->transaction(function (Database $db) use ($prepared, $delivery, $amount, $code): array {
            $order = $db->fetch("SELECT o.*,u.telegram_id,u.username,u.first_name,u.last_name,u.language FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? FOR UPDATE", [$prepared['order']['id']]);
            $locked = $db->fetch('SELECT * FROM payment_code_registry WHERE id=? FOR UPDATE', [$prepared['locked']['id']]);
            if ($order['status'] === 'completed' || $locked['status'] === 'processed') return ['result' => 'duplicate_code', 'outbox_id' => null];
            if ($order['status'] !== 'processing' || $locked['status'] !== 'reserved') {
                return ['result' => 'review', 'outbox_id' => null];
            }
            if (!$delivery['success']) {
                $params = ['order' => Helpers::escape((string) $order['order_code']), 'amount' => Helpers::money($amount)];
                $result = $this->refundOrder($order, $amount, $code, 'out_of_stock', 'order_result_out_of_stock', $params);
            } else {
                $result = $this->completePaidOrder($order, $amount, $delivery['items'] ?? []);
            }
            $this->finalizePaymentRegistry($locked, $result, $amount, $prepared['eventId']);
            return ['result' => $result['result'], 'outbox_id' => $result['outbox_id']];
        });
    }

    public function expireHybridOrders(int $limit = 100): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM orders WHERE payment_method='hybrid' AND status='pending' AND expires_at<=NOW() ORDER BY id LIMIT " . max(1, $limit)
        );
        $expired = 0;
        foreach ($rows as $row) {
            $didExpire = $this->db->transaction(function (Database $db) use ($row): bool {
                $order = $db->fetch(
                    "SELECT o.*,u.telegram_id,u.language FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? FOR UPDATE",
                    [(int) $row['id']]
                );
                if ($order === null || $order['status'] !== 'pending' || $order['payment_method'] !== 'hybrid'
                    || $order['expires_at'] === null || strtotime((string) $order['expires_at']) > time()) {
                    return false;
                }

                $user = $db->fetch('SELECT balance FROM users WHERE id=? FOR UPDATE', [$order['user_id']]);
                $walletAmount = number_format((float) $order['wallet_amount'], 2, '.', '');
                $balanceBefore = number_format((float) $user['balance'], 2, '.', '');
                $balanceAfter = number_format((float) $balanceBefore + (float) $walletAmount, 2, '.', '');
                $db->execute('UPDATE users SET balance=? WHERE id=?', [$balanceAfter, $order['user_id']]);
                $db->execute(
                    "INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,reference_type,reference_id,reference_code,note)
                     VALUES(?,'refund',?,?,?,'order',?,?,?)",
                    [$order['user_id'], $walletAmount, $balanceBefore, $balanceAfter, $order['id'], $order['order_code'], 'hybrid_expired']
                );
                $db->execute("UPDATE orders SET status='cancelled',result_reason='expired',wallet_credited=?,completed_at=NOW() WHERE id=?", [$walletAmount, $order['id']]);
                $this->outbox->enqueueRecipient(
                    (int) $order['user_id'],
                    'user',
                    (int) $order['telegram_id'],
                    (string) $order['language'],
                    'order:' . $order['id'] . ':expired',
                    'order_expired_wallet_refunded',
                    [
                        'amount' => Helpers::money($walletAmount),
                        'order' => (string) $order['order_code'],
                        'cleanup_request_type' => 'order',
                        'cleanup_request_id' => (int) $order['id'],
                    ]
                );
                return true;
            });
            if ($didExpire) $expired++;
        }
        return $expired;
    }

    public function expireDirectOrders(int $limit = 100): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM orders WHERE payment_method='vietqr_direct' AND status='pending' AND expires_at<=NOW() ORDER BY id LIMIT " . max(1, $limit)
        );
        $expired = 0;
        foreach ($rows as $row) {
            $didExpire = $this->db->transaction(function (Database $db) use ($row): bool {
                $order = $db->fetch(
                    "SELECT o.*,u.telegram_id,u.language FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? FOR UPDATE",
                    [(int) $row['id']]
                );
                if ($order === null || $order['status'] !== 'pending' || $order['payment_method'] !== 'vietqr_direct'
                    || $order['expires_at'] === null || strtotime((string) $order['expires_at']) > time()) {
                    return false;
                }
                $db->execute("UPDATE orders SET status='cancelled',result_reason='expired',completed_at=NOW() WHERE id=?", [$order['id']]);
                $this->outbox->enqueueRecipient(
                    (int) $order['user_id'],
                    'user',
                    (int) $order['telegram_id'],
                    (string) $order['language'],
                    'order:' . $order['id'] . ':expired',
                    'order_expired',
                    [
                        'order' => (string) $order['order_code'],
                        'cleanup_request_type' => 'order',
                        'cleanup_request_id' => (int) $order['id'],
                    ]
                );
                return true;
            });
            if ($didExpire) $expired++;
        }
        return $expired;
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
        $user = $this->db->fetch('SELECT * FROM users WHERE id=? FOR UPDATE', [$deposit['user_id']]);
        if ($user === null) return $this->result('review', false, null, $depositId);

        $referenceCode = trim((string) ($deposit['reference_code'] ?? ''));
        if ($referenceCode === '') $referenceCode = 'NAP-' . str_pad((string) $depositId, 6, '0', STR_PAD_LEFT);
        $balanceBefore = number_format((float) $user['balance'], 2, '.', '');
        $balanceAfter = number_format((float) $balanceBefore + (float) $amount, 2, '.', '');
        $completedAt = date('Y-m-d H:i:s');
        $displayTime = Helpers::dateTime($completedAt);

        $this->db->execute('UPDATE users SET balance=? WHERE id=?', [$balanceAfter, $deposit['user_id']]);
        $this->db->execute(
            "UPDATE deposits SET reference_code=?,amount=?,balance_before=?,balance_after=?,status='completed',completed_at=? WHERE id=?",
            [$referenceCode, $amount, $balanceBefore, $balanceAfter, $completedAt, $depositId]
        );
        $this->db->execute(
            "INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,reference_type,reference_id,reference_code,note)
             VALUES(?,'deposit',?,?,?,'deposit',?,?,?)",
            [$deposit['user_id'], $amount, $balanceBefore, $balanceAfter, $depositId, $referenceCode, 'deposit_completed']
        );

        $params = [
            'amount' => Helpers::money($amount),
            'balance' => Helpers::money($balanceAfter),
            'code' => $referenceCode,
            'time' => $displayTime,
            'cleanup_request_type' => 'deposit',
            'cleanup_request_id' => $depositId,
        ];
        $textLink = $this->db->fetch(
            "SELECT * FROM payment_message_links WHERE request_type='deposit' AND request_id=? AND message_type='text' AND is_active=1 ORDER BY id DESC LIMIT 1",
            [$depositId]
        );
        $outboxId = $this->outbox->enqueueRecipient(
            (int) $deposit['user_id'],
            'user',
            (int) $user['telegram_id'],
            (string) $user['language'],
            'deposit:' . $depositId . ':user',
            'deposit_success_detailed',
            $params,
            $textLink === null ? 'send' : 'edit_or_send',
            $textLink === null ? null : (int) $textLink['chat_id'],
            $textLink === null ? null : (int) $textLink['message_id']
        );

        $customer = trim((string) $user['first_name'] . ' ' . (string) ($user['last_name'] ?? ''));
        if (trim((string) ($user['username'] ?? '')) !== '') $customer .= ' · @' . trim((string) $user['username']);
        foreach (Helpers::adminTelegramIds($this->app) as $adminId) {
            $this->outbox->enqueueAdmin($adminId, 'deposit:' . $depositId . ':admin:' . $adminId, 'admin_deposit_success', [
                'customer' => $customer !== '' ? $customer : '-',
                'telegram_id' => (string) $user['telegram_id'],
                'amount' => Helpers::money($amount),
                'balance' => Helpers::money($balanceAfter),
                'payment_code' => $code,
                'code' => $referenceCode,
                'time' => $displayTime,
            ]);
        }
        return $this->result('deposit_completed', true, null, $depositId, '0.00', $amount, $outboxId);
    }

    private function processOrder(int $orderId, string $amount, string $code): array
    {
        $order = $this->db->fetch("SELECT o.*,v.status variant_status,p.status product_status,u.telegram_id,u.username,u.first_name,u.last_name,u.language FROM orders o JOIN variants v ON v.id=o.variant_id JOIN products p ON p.id=v.product_id JOIN users u ON u.id=o.user_id WHERE o.id=? FOR UPDATE", [$orderId]);
        if ($order === null) return $this->result('review', false, $orderId);
        $this->db->fetch('SELECT id FROM users WHERE id=? FOR UPDATE', [$order['user_id']]);
        $params = ['order' => Helpers::escape((string) $order['order_code']), 'amount' => Helpers::money($amount)];
        if ($order['status'] !== 'pending') {
            $this->creditWallet($order, $amount, 'closed_order_payment');
            $outboxId = $this->outbox->enqueue((int) $order['user_id'], 'payment-code:' . $code, 'order_result_closed', $params);
            return $this->result('closed_order_wallet', true, $orderId, null, '0.00', $amount, $outboxId);
        }
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
        $total = number_format((float) $order['amount'] + (float) $order['wallet_amount'], 2, '.', '');
        $completedAt = date('Y-m-d H:i:s');
        $this->db->execute("UPDATE orders SET stock_content=?,status='completed',result_reason='delivered',wallet_credited=?,paid_at=?,completed_at=? WHERE id=?", [implode("\n", $contents), max(0, (float) $extra), $completedAt, $completedAt, $orderId]);
        $this->db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,reference_code,note) VALUES(?,'purchase',?,'order',?,?,?)", [$order['user_id'], -((float) $order['amount']), $orderId, $order['order_code'], $order['order_code']]);
        if ((float) $extra > 0) $this->creditWallet($order, $extra, 'overpayment');
        $params['product'] = (string) ($order['product_name'] ?: '-');
        $params['quantity'] = (int) $order['quantity'];
        $params['amount'] = Helpers::money($total);
        $params['time'] = Helpers::dateTime($completedAt);
        $params['extra'] = (float) $extra > 0 ? Helpers::money($extra) : '';
        $params['deliveries'] = $contents;
        $params['cleanup_request_type'] = 'order';
        $params['cleanup_request_id'] = $orderId;
        $outboxId = $this->outbox->enqueue((int) $order['user_id'], 'order:' . $orderId . ':user', 'order_result_success', $params);
        $order['total_amount'] = $total;
        $this->enqueueOrderAdmins($order, Helpers::dateTime($completedAt));
        return $this->result('order_delivered', true, $orderId, null, $total, $extra, $outboxId);
    }

    private function completePaidOrder(array $order, string $amount, array $contents): array
    {
        $extra = number_format((float) $amount - (float) $order['amount'], 2, '.', '');
        $total = number_format((float) $order['amount'] + (float) $order['wallet_amount'], 2, '.', '');
        $completedAt = date('Y-m-d H:i:s');
        $this->db->execute("UPDATE orders SET stock_content=?,status='completed',result_reason='delivered',wallet_credited=?,completed_at=? WHERE id=?", [implode("\n", $contents), max(0, (float) $extra), $completedAt, $order['id']]);
        $this->db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,reference_code,note) VALUES(?,'purchase',?,'order',?,?,?)", [$order['user_id'], -((float) $order['amount']), $order['id'], $order['order_code'], $order['order_code']]);
        if ((float) $extra > 0) $this->creditWallet($order, $extra, 'overpayment');
        $params = ['order' => Helpers::escape((string) $order['order_code']), 'product' => (string) ($order['product_name'] ?: '-'), 'quantity' => (int) $order['quantity'], 'amount' => Helpers::money($total), 'time' => Helpers::dateTime($completedAt), 'extra' => (float) $extra > 0 ? Helpers::money($extra) : '', 'deliveries' => $contents, 'cleanup_request_type' => 'order', 'cleanup_request_id' => (int) $order['id']];
        $outboxId = $this->outbox->enqueue((int) $order['user_id'], 'order:' . $order['id'] . ':user', 'order_result_success', $params);
        $order['total_amount'] = $total;
        $this->enqueueOrderAdmins($order, Helpers::dateTime($completedAt));
        return $this->result('order_delivered', true, (int) $order['id'], null, $total, $extra, $outboxId);
    }

    private function finalizePaymentRegistry(array $registry, array $result, string $amount, ?int $eventId): void
    {
        if ($result['final']) {
            $this->db->execute("UPDATE payment_code_registry SET status='processed',processed_amount=?,result_type=?,processed_event_id=?,processed_at=NOW() WHERE id=?", [$amount, $result['result'], $eventId, $registry['id']]);
        }
        if ($eventId !== null) {
            $this->db->execute('UPDATE payment_events SET order_id=?,deposit_id=?,result_type=?,purchase_amount=?,wallet_amount=? WHERE id=?', [$result['order_id'], $result['deposit_id'], $result['result'], $result['purchase_amount'], $result['wallet_amount'], $eventId]);
        }
    }

    private function refundOrder(array $order, string $amount, string $code, string $reason, string $messageKey, array $params): array
    {
        $refund = number_format((float) $amount + (float) $order['wallet_amount'], 2, '.', '');
        $this->creditWallet($order, $refund, $reason);
        $this->db->execute("UPDATE orders SET status='failed',result_reason=?,wallet_credited=?,paid_at=NOW(),completed_at=NOW() WHERE id=?", [$reason, $refund, $order['id']]);
        $params['amount'] = Helpers::money($refund);
        $outboxId = $this->outbox->enqueue((int) $order['user_id'], 'payment-code:' . $code, $messageKey, $params);
        return $this->result($reason . '_wallet', true, (int) $order['id'], null, '0.00', $refund, $outboxId);
    }

    private function enqueueOrderAdmins(array $order, string $displayTime): void
    {
        $customer = trim((string) $order['first_name'] . ' ' . (string) ($order['last_name'] ?? ''));
        if (trim((string) ($order['username'] ?? '')) !== '') $customer .= ' · @' . trim((string) $order['username']);
        foreach (Helpers::adminTelegramIds($this->app) as $adminId) {
            $this->outbox->enqueueAdmin($adminId, 'order:' . $order['id'] . ':admin:' . $adminId, 'admin_order_success', [
                'customer' => $customer !== '' ? $customer : '-',
                'telegram_id' => (string) $order['telegram_id'],
                'product' => (string) ($order['product_name'] ?: '-'),
                'quantity' => (int) $order['quantity'],
                'amount' => Helpers::money($order['total_amount'] ?? ((float) $order['amount'] + (float) $order['wallet_amount'])),
                'payment_method' => (new Language('vi'))->get('payment_method_' . $order['payment_method']),
                'code' => (string) $order['order_code'],
                'time' => $displayTime,
            ]);
        }
    }

    private function creditWallet(array $order, string $amount, string $reason): void
    {
        $this->db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $order['user_id']]);
        $this->db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,reference_code,note) VALUES(?,'refund',?,'order',?,?,?)", [$order['user_id'], $amount, $order['id'], $order['order_code'], $reason]);
    }

    private function result(string $result, bool $final, ?int $orderId = null, ?int $depositId = null, string $purchase = '0.00', string $wallet = '0.00', ?int $outboxId = null): array
    {
        return compact('result', 'final', 'orderId', 'depositId', 'purchase', 'wallet', 'outboxId') + ['order_id' => $orderId, 'deposit_id' => $depositId, 'purchase_amount' => $purchase, 'wallet_amount' => $wallet, 'outbox_id' => $outboxId];
    }
}
