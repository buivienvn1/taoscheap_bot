<?php

declare(strict_types=1);

final class PaymentProcessor
{
    public function __construct(
        private Database $db,
        private PaymentNotificationService $notifications,
        private string $walletCurrency = 'VND',
        private string $minimumDeposit = '2000.00',
    ) {
    }

    public function process(PaymentEvent $event): ?int
    {
        if ($event->direction !== 'incoming') {
            return null;
        }

        return $this->db->transaction(function (Database $db) use ($event): ?int {
            $paymentEventId = $this->insertEvent($db, $event);
            if ($paymentEventId === null) {
                return null;
            }
            if ($event->currency !== $this->walletCurrency) {
                $this->setResult($db, $paymentEventId, 'unsupported_currency');
                return null;
            }

            $amount = $this->money($event->amount);
            $normalized = Helpers::normalizePaymentContent($event->reference);
            $deposits = $db->fetchAll(
                "SELECT d.*,u.telegram_id,u.language
                 FROM deposits d JOIN users u ON u.id=d.user_id
                 WHERE d.status='pending' AND ? LIKE CONCAT('%',UPPER(d.code),'%')
                 ORDER BY d.id LIMIT 2 FOR UPDATE",
                [$normalized]
            );
            if (count($deposits) > 1) {
                $this->setResult($db, $paymentEventId, 'ambiguous_reference');
                return null;
            }
            if (count($deposits) === 1) {
                return $this->processDeposit($db, $paymentEventId, $event, $deposits[0], $amount);
            }

            $orders = $db->fetchAll(
                "SELECT o.*,u.telegram_id,u.language,v.status variant_status,p.status product_status
                 FROM orders o
                 JOIN users u ON u.id=o.user_id
                 JOIN variants v ON v.id=o.variant_id
                 JOIN products p ON p.id=v.product_id
                 WHERE o.payment_code IS NOT NULL AND ? LIKE CONCAT('%',UPPER(o.payment_code),'%')
                 ORDER BY o.id LIMIT 2 FOR UPDATE",
                [$normalized]
            );
            if (count($orders) > 1) {
                $this->setResult($db, $paymentEventId, 'ambiguous_reference');
                return null;
            }
            if ($orders === []) {
                $this->setResult($db, $paymentEventId, 'unmatched');
                return null;
            }

            return $this->processOrder($db, $paymentEventId, $event, $orders[0], $amount);
        });
    }

    private function insertEvent(Database $db, PaymentEvent $event): ?int
    {
        try {
            $db->execute(
                'INSERT INTO payment_events
                 (provider,provider_event_id,destination_account,transfer_content,amount,currency,direction,occurred_at,payload,result_type)
                 VALUES(?,?,?,?,?,?,?,?,?,?)',
                [
                    $event->provider,
                    $event->providerEventId,
                    $event->destinationAccount,
                    $event->reference,
                    $this->money($event->amount),
                    $event->currency,
                    $event->direction,
                    $event->occurredAt?->format('Y-m-d H:i:s'),
                    $event->payloadJson(),
                    'received',
                ]
            );
        } catch (PDOException $error) {
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            if ((string) $error->getCode() === '23000' && $driverCode === 1062) {
                return null;
            }
            throw $error;
        }

        return $db->lastInsertId();
    }

    private function processDeposit(
        Database $db,
        int $paymentEventId,
        PaymentEvent $event,
        array $deposit,
        string $amount,
    ): ?int {
        if ($this->compareMoney($amount, $this->money($this->minimumDeposit)) < 0) {
            $db->execute(
                "UPDATE payment_events SET deposit_id=?,result_type='deposit_below_minimum' WHERE id=?",
                [$deposit['id'], $paymentEventId]
            );
            return null;
        }

        $db->fetch('SELECT id FROM users WHERE id=? FOR UPDATE', [$deposit['user_id']]);
        $db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $deposit['user_id']]);
        $db->execute(
            "UPDATE deposits SET amount=?,status='completed',completed_at=NOW() WHERE id=?",
            [$amount, $deposit['id']]
        );
        $db->execute(
            "INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note)
             VALUES(?,'deposit',?,'deposit',?,?)",
            [$deposit['user_id'], $amount, $deposit['id'], 'deposit_completed']
        );
        $db->execute(
            "UPDATE payment_events SET deposit_id=?,result_type='deposit_completed',wallet_amount=? WHERE id=?",
            [$deposit['id'], $amount, $paymentEventId]
        );

        return $this->notifications->enqueue(
            $db,
            (int) $deposit['user_id'],
            $event->idempotencyKey(),
            'deposit_success',
            ['amount' => Helpers::money($amount)]
        );
    }

    private function processOrder(
        Database $db,
        int $paymentEventId,
        PaymentEvent $event,
        array $order,
        string $amount,
    ): ?int {
        $db->fetch('SELECT id FROM users WHERE id=? FOR UPDATE', [$order['user_id']]);
        $db->execute('UPDATE payment_events SET order_id=? WHERE id=?', [$order['id'], $paymentEventId]);
        $params = ['order' => Helpers::escape($order['order_code']), 'amount' => Helpers::money($amount)];

        if ($order['status'] !== 'pending') {
            $this->creditWallet($db, $order, $amount, 'closed_order_payment');
            $db->execute(
                "UPDATE payment_events SET result_type='closed_order_wallet',wallet_amount=? WHERE id=?",
                [$amount, $paymentEventId]
            );
            return $this->enqueueOrder($db, $event, $order, 'order_result_closed', $params);
        }

        $orderAmount = $this->money((string) $order['amount']);
        if ($this->compareMoney($amount, $orderAmount) < 0) {
            $this->creditWallet($db, $order, $amount, 'short_payment');
            $this->closeOrder($db, (int) $order['id'], 'short_payment', $amount);
            $db->execute(
                "UPDATE payment_events SET result_type='short_payment_wallet',wallet_amount=? WHERE id=?",
                [$amount, $paymentEventId]
            );
            return $this->enqueueOrder($db, $event, $order, 'order_result_short_payment', $params);
        }

        if (!(int) $order['variant_status'] || !(int) $order['product_status']) {
            $this->creditWallet($db, $order, $amount, 'product_unavailable');
            $this->closeOrder($db, (int) $order['id'], 'product_unavailable', $amount);
            $db->execute(
                "UPDATE payment_events SET result_type='product_unavailable_wallet',wallet_amount=? WHERE id=?",
                [$amount, $paymentEventId]
            );
            return $this->enqueueOrder($db, $event, $order, 'order_result_unavailable', $params);
        }

        $quantity = max(1, (int) $order['quantity']);
        $stocks = $db->fetchAll(
            'SELECT id,content FROM stocks WHERE variant_id=? AND is_sold=0 ORDER BY id LIMIT '
            . $quantity . ' FOR UPDATE',
            [$order['variant_id']]
        );
        if (count($stocks) !== $quantity) {
            $this->creditWallet($db, $order, $amount, 'out_of_stock');
            $this->closeOrder($db, (int) $order['id'], 'out_of_stock', $amount);
            $db->execute(
                "UPDATE payment_events SET result_type='out_of_stock_wallet',wallet_amount=? WHERE id=?",
                [$amount, $paymentEventId]
            );
            return $this->enqueueOrder($db, $event, $order, 'order_result_out_of_stock', $params);
        }

        $contents = [];
        foreach ($stocks as $stock) {
            $updated = $db->execute(
                'UPDATE stocks SET is_sold=1,order_id=?,sold_at=NOW() WHERE id=? AND is_sold=0',
                [$order['id'], $stock['id']]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Stock allocation conflict');
            }
            $db->execute(
                'INSERT INTO order_deliveries(order_id,stock_id,content) VALUES(?,?,?)',
                [$order['id'], $stock['id'], $stock['content']]
            );
            $contents[] = $stock['content'];
        }

        $extra = $this->subtractMoney($amount, $orderAmount);
        $db->execute(
            "UPDATE orders SET stock_content=?,status='completed',result_reason='delivered',wallet_credited=?,paid_at=NOW(),completed_at=NOW() WHERE id=?",
            [implode("\n", $contents), $extra, $order['id']]
        );
        $db->execute(
            "INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note)
             VALUES(?,'purchase',?,'order',?,?)",
            [$order['user_id'], '-' . $orderAmount, $order['id'], $order['order_code']]
        );
        if ($this->compareMoney($extra, '0.00') > 0) {
            $this->creditWallet($db, $order, $extra, 'overpayment');
        }
        $db->execute(
            "UPDATE payment_events SET result_type='order_delivered',purchase_amount=?,wallet_amount=? WHERE id=?",
            [$orderAmount, $extra, $paymentEventId]
        );
        $params['extra'] = $this->compareMoney($extra, '0.00') > 0 ? Helpers::money($extra) : '';
        $params['deliveries'] = $contents;

        return $this->enqueueOrder($db, $event, $order, 'order_result_success', $params);
    }

    private function enqueueOrder(Database $db, PaymentEvent $event, array $order, string $messageKey, array $params): int
    {
        return $this->notifications->enqueue(
            $db,
            (int) $order['user_id'],
            $event->idempotencyKey(),
            $messageKey,
            $params
        );
    }

    private function creditWallet(Database $db, array $order, string $amount, string $reason): void
    {
        $db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $order['user_id']]);
        $db->execute(
            "INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note)
             VALUES(?,'refund',?,'order',?,?)",
            [$order['user_id'], $amount, $order['id'], $reason]
        );
    }

    private function closeOrder(Database $db, int $orderId, string $reason, string $walletAmount): void
    {
        $db->execute(
            "UPDATE orders SET status='failed',result_reason=?,wallet_credited=?,paid_at=NOW(),completed_at=NOW() WHERE id=?",
            [$reason, $walletAmount, $orderId]
        );
    }

    private function setResult(Database $db, int $paymentEventId, string $result): void
    {
        $db->execute('UPDATE payment_events SET result_type=? WHERE id=?', [$result, $paymentEventId]);
    }

    private function money(string $amount): string
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,8}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('Invalid monetary amount');
        }
        $rawFraction = $matches[2] ?? '';
        if (strlen($rawFraction) > 2 && trim(substr($rawFraction, 2), '0') !== '') {
            throw new InvalidArgumentException('Payment precision exceeds wallet precision');
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = substr(str_pad($rawFraction, 2, '0'), 0, 2);
        return $whole . '.' . $fraction;
    }

    private function compareMoney(string $left, string $right): int
    {
        return $this->toMinorUnits($left) <=> $this->toMinorUnits($right);
    }

    private function subtractMoney(string $left, string $right): string
    {
        $difference = $this->toMinorUnits($left) - $this->toMinorUnits($right);
        if ($difference < 0) {
            throw new LogicException('Negative monetary difference');
        }
        return intdiv($difference, 100) . '.' . str_pad((string) ($difference % 100), 2, '0', STR_PAD_LEFT);
    }

    private function toMinorUnits(string $amount): int
    {
        $normalized = $this->money($amount);
        [$whole, $fraction] = explode('.', $normalized, 2);
        if (strlen($whole) > 13) {
            throw new OverflowException('Monetary amount exceeds wallet capacity');
        }
        return ((int) $whole * 100) + (int) $fraction;
    }
}
