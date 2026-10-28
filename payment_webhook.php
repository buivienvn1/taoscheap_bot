<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$providedSecret = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
$providedSecret = preg_replace('/^Bearer\s+/i', '', $providedSecret) ?? '';
if (!hash_equals($app['payment_secret'], trim($providedSecret))) {
    http_response_code(403);
    exit('Forbidden');
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('Invalid payload');
}

$eventId = trim((string) ($payload['id'] ?? $payload['referenceCode'] ?? ''));
$content = (string) ($payload['content'] ?? $payload['description'] ?? '');
$amount = number_format((float) ($payload['transferAmount'] ?? $payload['amount'] ?? 0), 2, '.', '');
$isIncoming = !isset($payload['transferType']) || strtolower((string) $payload['transferType']) === 'in';
if ($eventId === '' || (float) $amount <= 0 || !$isIncoming) {
    http_response_code(422);
    exit('Ignored');
}

try {
    $outboxId = $db->transaction(function (Database $db) use ($payload, $eventId, $content, $amount, $app): ?int {
        try {
            $db->execute(
                'INSERT INTO payment_events(provider,provider_event_id,transfer_content,amount,payload,result_type) VALUES(?,?,?,?,?,?)',
                [$app['payment_provider'], $eventId, $content, $amount, json_encode($payload, JSON_UNESCAPED_UNICODE), 'received']
            );
        } catch (PDOException $error) {
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            if ((string) $error->getCode() === '23000' && $driverCode === 1062) return null;
            throw $error;
        }
        $paymentEventId = $db->lastInsertId();
        $normalized = Helpers::normalizePaymentContent($content);

        $deposit = $db->fetch(
            "SELECT d.*,u.telegram_id,u.language FROM deposits d JOIN users u ON u.id=d.user_id
             WHERE d.status='pending' AND ? LIKE CONCAT('%',UPPER(d.code),'%') ORDER BY d.id LIMIT 1 FOR UPDATE",
            [$normalized]
        );
        if ($deposit !== null) {
            if ((float) $amount < (float) $app['min_deposit']) {
                $db->execute("UPDATE payment_events SET deposit_id=?,result_type='deposit_below_minimum' WHERE id=?", [$deposit['id'], $paymentEventId]);
                return null;
            }
            $db->fetch('SELECT id FROM users WHERE id=? FOR UPDATE', [$deposit['user_id']]);
            $db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $deposit['user_id']]);
            $db->execute("UPDATE deposits SET amount=?,status='completed',completed_at=NOW() WHERE id=?", [$amount, $deposit['id']]);
            $db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note) VALUES(?,'deposit',?,'deposit',?,?)", [$deposit['user_id'], $amount, $deposit['id'], 'deposit_completed']);
            $db->execute("UPDATE payment_events SET deposit_id=?,result_type='deposit_completed',wallet_amount=? WHERE id=?", [$deposit['id'], $amount, $paymentEventId]);
            return enqueueNotification($db, (int) $deposit['user_id'], $app['payment_provider'] . ':' . $eventId, 'deposit_success', ['amount' => Helpers::money($amount)]);
        }

        $order = $db->fetch(
            "SELECT o.*,u.telegram_id,u.language,v.status variant_status,p.status product_status
             FROM orders o JOIN users u ON u.id=o.user_id JOIN variants v ON v.id=o.variant_id JOIN products p ON p.id=v.product_id
             WHERE o.payment_code IS NOT NULL AND ? LIKE CONCAT('%',UPPER(o.payment_code),'%')
             ORDER BY o.id LIMIT 1 FOR UPDATE",
            [$normalized]
        );
        if ($order === null) {
            $db->execute("UPDATE payment_events SET result_type='unmatched' WHERE id=?", [$paymentEventId]);
            return null;
        }
        $db->fetch('SELECT id FROM users WHERE id=? FOR UPDATE', [$order['user_id']]);
        $db->execute('UPDATE payment_events SET order_id=? WHERE id=?', [$order['id'], $paymentEventId]);
        $params = ['order' => Helpers::escape($order['order_code']), 'amount' => Helpers::money($amount)];

        if ($order['status'] !== 'pending') {
            creditWallet($db, $order, $amount, 'closed_order_payment');
            $db->execute("UPDATE payment_events SET result_type='closed_order_wallet',wallet_amount=? WHERE id=?", [$amount, $paymentEventId]);
            return enqueueNotification($db, (int) $order['user_id'], $app['payment_provider'] . ':' . $eventId, 'order_result_closed', $params);
        }
        if ((float) $amount < (float) $order['amount']) {
            creditWallet($db, $order, $amount, 'short_payment');
            closeOrder($db, (int) $order['id'], 'short_payment', $amount);
            $db->execute("UPDATE payment_events SET result_type='short_payment_wallet',wallet_amount=? WHERE id=?", [$amount, $paymentEventId]);
            return enqueueNotification($db, (int) $order['user_id'], $app['payment_provider'] . ':' . $eventId, 'order_result_short_payment', $params);
        }
        if (!(int) $order['variant_status'] || !(int) $order['product_status']) {
            creditWallet($db, $order, $amount, 'product_unavailable');
            closeOrder($db, (int) $order['id'], 'product_unavailable', $amount);
            $db->execute("UPDATE payment_events SET result_type='product_unavailable_wallet',wallet_amount=? WHERE id=?", [$amount, $paymentEventId]);
            return enqueueNotification($db, (int) $order['user_id'], $app['payment_provider'] . ':' . $eventId, 'order_result_unavailable', $params);
        }

        $quantity = max(1, (int) $order['quantity']);
        $stocks = $db->fetchAll(
            'SELECT id,content FROM stocks WHERE variant_id=? AND is_sold=0 ORDER BY id LIMIT ' . $quantity . ' FOR UPDATE',
            [$order['variant_id']]
        );
        if (count($stocks) !== $quantity) {
            creditWallet($db, $order, $amount, 'out_of_stock');
            closeOrder($db, (int) $order['id'], 'out_of_stock', $amount);
            $db->execute("UPDATE payment_events SET result_type='out_of_stock_wallet',wallet_amount=? WHERE id=?", [$amount, $paymentEventId]);
            return enqueueNotification($db, (int) $order['user_id'], $app['payment_provider'] . ':' . $eventId, 'order_result_out_of_stock', $params);
        }

        $contents = [];
        foreach ($stocks as $stock) {
            $updated = $db->execute('UPDATE stocks SET is_sold=1,order_id=?,sold_at=NOW() WHERE id=? AND is_sold=0', [$order['id'], $stock['id']]);
            if ($updated !== 1) throw new RuntimeException('Stock allocation conflict');
            $db->execute('INSERT INTO order_deliveries(order_id,stock_id,content) VALUES(?,?,?)', [$order['id'], $stock['id'], $stock['content']]);
            $contents[] = $stock['content'];
        }
        $extra = number_format((float) $amount - (float) $order['amount'], 2, '.', '');
        $db->execute(
            "UPDATE orders SET stock_content=?,status='completed',result_reason='delivered',wallet_credited=?,paid_at=NOW(),completed_at=NOW() WHERE id=?",
            [implode("\n", $contents), max(0, (float) $extra), $order['id']]
        );
        $db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note) VALUES(?,'purchase',?,'order',?,?)", [$order['user_id'], -((float) $order['amount']), $order['id'], $order['order_code']]);
        if ((float) $extra > 0) creditWallet($db, $order, $extra, 'overpayment');
        $db->execute("UPDATE payment_events SET result_type='order_delivered',purchase_amount=?,wallet_amount=? WHERE id=?", [$order['amount'], max(0, (float) $extra), $paymentEventId]);
        $params['extra'] = (float) $extra > 0 ? Helpers::money($extra) : '';
        $params['deliveries'] = $contents;
        return enqueueNotification($db, (int) $order['user_id'], $app['payment_provider'] . ':' . $eventId, 'order_result_success', $params);
    });

    if ($outboxId !== null) deliverOutbox($db, $telegram, $outboxId);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    error_log('Payment webhook failed: ' . $error::class);
    http_response_code(500);
    echo json_encode(['success' => false]);
}

function creditWallet(Database $db, array $order, string $amount, string $reason): void
{
    $db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $order['user_id']]);
    $db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note) VALUES(?,'refund',?,'order',?,?)", [$order['user_id'], $amount, $order['id'], $reason]);
}

function closeOrder(Database $db, int $orderId, string $reason, string $walletAmount): void
{
    $db->execute("UPDATE orders SET status='failed',result_reason=?,wallet_credited=?,paid_at=NOW(),completed_at=NOW() WHERE id=?", [$reason, $walletAmount, $orderId]);
}

function enqueueNotification(Database $db, int $userId, string $eventKey, string $messageKey, array $params): int
{
    $db->execute(
        "INSERT INTO notification_outbox(user_id,event_key,message_key,message_params,status) VALUES(?,?,?,?,'pending')",
        [$userId, $eventKey, $messageKey, json_encode($params, JSON_UNESCAPED_UNICODE)]
    );
    return $db->lastInsertId();
}

function deliverOutbox(Database $db, Telegram $telegram, int $outboxId): void
{
    $item = $db->fetch('SELECT n.*,u.telegram_id,u.language FROM notification_outbox n JOIN users u ON u.id=n.user_id WHERE n.id=?', [$outboxId]);
    if ($item === null || $item['status'] === 'sent') return;
    $lang = new Language(in_array($item['language'], ['vi', 'en'], true) ? $item['language'] : 'vi');
    $params = json_decode($item['message_params'], true) ?: [];
    $text = $lang->get($item['message_key'], array_filter($params, fn ($value) => !is_array($value)));
    if ($item['message_key'] === 'order_result_success') {
        if (($params['extra'] ?? '') !== '') $text .= "\n" . $lang->get('order_extra_wallet', ['amount' => $params['extra']]);
        foreach (($params['deliveries'] ?? []) as $index => $content) {
            $text .= "\n\n<b>" . $lang->get('delivery_item', ['number' => $index + 1]) . ":</b>\n<code>" . Helpers::escape($content) . '</code>';
        }
    }
    try {
        $telegram->sendMessage((int) $item['telegram_id'], $text);
        $db->execute("UPDATE notification_outbox SET status='sent',attempts=attempts+1,sent_at=NOW() WHERE id=?", [$outboxId]);
    } catch (Throwable $error) {
        $db->execute("UPDATE notification_outbox SET status='failed',attempts=attempts+1 WHERE id=?", [$outboxId]);
        error_log('Payment notification failed: ' . $error::class);
    }
}
