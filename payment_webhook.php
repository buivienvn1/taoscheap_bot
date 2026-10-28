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

$eventId = (string) ($payload['id'] ?? $payload['referenceCode'] ?? '');
$content = (string) ($payload['content'] ?? $payload['description'] ?? '');
$amount = (float) ($payload['transferAmount'] ?? $payload['amount'] ?? 0);
$isIncoming = !isset($payload['transferType']) || strtolower((string) $payload['transferType']) === 'in';
if ($eventId === '' || $amount <= 0 || !$isIncoming) {
    http_response_code(422);
    exit('Ignored');
}

try {
    $notification = $db->transaction(function (Database $db) use ($payload, $eventId, $content, $amount, $app): ?array {
        try {
            $db->execute('INSERT INTO payment_events (provider, provider_event_id, transfer_content, amount, payload) VALUES (?, ?, ?, ?, ?)', [$app['payment_provider'], $eventId, $content, $amount, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') return null;
            throw $error;
        }

        $normalized = Helpers::normalizePaymentContent($content);
        $deposit = $db->fetch("SELECT d.*, u.telegram_id FROM deposits d JOIN users u ON u.id=d.user_id WHERE d.status='pending' AND ? LIKE CONCAT('%', UPPER(d.code), '%') ORDER BY d.id LIMIT 1 FOR UPDATE", [$normalized]);
        if ($deposit !== null) {
            if ($amount < (float) $app['min_deposit']) return null;
            $db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $deposit['user_id']]);
            $db->execute("UPDATE deposits SET amount=?,status='completed',completed_at=NOW() WHERE id=?", [$amount, $deposit['id']]);
            $db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note)VALUES(?,'deposit',?,'deposit',?,?)", [$deposit['user_id'], $amount, $deposit['id'], $deposit['code']]);
            return ['chat_id' => $deposit['telegram_id'], 'text' => '✅ Nạp thành công ' . Helpers::money($amount) . '.'];
        }

        $order = $db->fetch("SELECT o.*,u.telegram_id,v.name variant_name FROM orders o JOIN users u ON u.id=o.user_id JOIN variants v ON v.id=o.variant_id WHERE o.status='pending' AND ? LIKE CONCAT('%',UPPER(o.payment_code),'%') ORDER BY o.id LIMIT 1 FOR UPDATE", [$normalized]);
        if ($order === null || $amount < (float) $order['amount']) return null;

        $stock = $db->fetch('SELECT id,content FROM stocks WHERE variant_id=? AND is_sold=0 ORDER BY id LIMIT 1 FOR UPDATE', [$order['variant_id']]);
        if ($stock === null) {
            $db->execute("UPDATE orders SET status='failed',paid_at=NOW() WHERE id=?", [$order['id']]);
            $db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $order['user_id']]);
            $db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note)VALUES(?,'refund',?,'order',?,'Hết hàng, hoàn vào ví')", [$order['user_id'], $amount, $order['id']]);
            return ['chat_id' => $order['telegram_id'], 'text' => 'Sản phẩm vừa hết hàng. ' . Helpers::money($amount) . ' đã được cộng vào ví.'];
        }

        $db->execute('UPDATE stocks SET is_sold=1,order_id=?,sold_at=NOW() WHERE id=?', [$order['id'], $stock['id']]);
        $db->execute("UPDATE orders SET stock_content=?,status='completed',paid_at=NOW(),completed_at=NOW() WHERE id=?", [$stock['content'], $order['id']]);
        $db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note)VALUES(?,'purchase',?,'order',?,?)", [$order['user_id'], -((float) $order['amount']), $order['id'], $order['order_code']]);
        $extra = $amount - (float) $order['amount'];
        if ($extra > 0) {
            $db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$extra, $order['user_id']]);
            $db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note)VALUES(?,'deposit',?,'order',?,'Tiền chuyển thừa')", [$order['user_id'], $extra, $order['id']]);
        }
        return ['chat_id' => $order['telegram_id'], 'text' => "✅ <b>Thanh toán thành công</b>\n\nSản phẩm: " . Helpers::escape($order['variant_name']) . "\nThông tin nhận:\n<code>" . Helpers::escape($stock['content']) . '</code>'];
    });

    if ($notification !== null) $telegram->sendMessage($notification['chat_id'], $notification['text']);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false]);
}
