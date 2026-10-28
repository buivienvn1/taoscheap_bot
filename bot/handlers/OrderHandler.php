<?php

declare(strict_types=1);

final class OrderHandler
{
    public function __construct(private Database $db, private Telegram $telegram, private VietQR $vietQr)
    {
    }

    public function buy(int $chatId, array $user, int $variantId): void
    {
        $variant = $this->db->fetch('SELECT v.*, p.name product_name FROM variants v JOIN products p ON p.id = v.product_id WHERE v.id = ? AND v.status = 1 AND p.status = 1', [$variantId]);
        if ($variant === null) {
            $this->telegram->sendMessage($chatId, 'Sản phẩm không còn khả dụng.');
            return;
        }
        if ($variant['supplier_type'] !== 'manual') {
            $this->telegram->sendMessage($chatId, 'Nguồn API đang chờ cấu hình nhà cung cấp.');
            return;
        }

        if ((float) $user['balance'] >= (float) $variant['price']) {
            $result = $this->purchaseWithWallet((int) $user['id'], $variant);
            $this->telegram->sendMessage($chatId, $result['message'], [[['text' => '📦 Tiếp tục mua', 'callback_data' => 'products'], ['text' => '🏠 Menu', 'callback_data' => 'start']]]);
            return;
        }

        $paymentCode = $this->uniqueCode();
        $orderCode = Helpers::orderCode();
        $this->db->execute("INSERT INTO orders (order_code, payment_code, user_id, variant_id, amount, payment_method, status) VALUES (?, ?, ?, ?, ?, 'vietqr_direct', 'pending')", [$orderCode, $paymentCode, $user['id'], $variantId, $variant['price']]);
        $caption = "<blockquote>THANH TOÁN ĐƠN HÀNG</blockquote>\n\nSản phẩm: " . Helpers::escape($variant['product_name'] . ' - ' . $variant['name']) . "\nSố tiền: <b>" . Helpers::money($variant['price']) . "</b>\nNội dung: <code>" . $paymentCode . "</code>";
        $this->telegram->sendPhoto($chatId, $this->vietQr->imageUrl($paymentCode, (float) $variant['price']), $caption, [[['text' => '⬅ Sản phẩm', 'callback_data' => 'products']]]);
    }

    private function purchaseWithWallet(int $userId, array $variant): array
    {
        return $this->db->transaction(function (Database $db) use ($userId, $variant): array {
            $user = $db->fetch('SELECT balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $stock = $db->fetch('SELECT id, content FROM stocks WHERE variant_id = ? AND is_sold = 0 ORDER BY id LIMIT 1 FOR UPDATE', [$variant['id']]);
            if ($stock === null) {
                return ['message' => 'Sản phẩm vừa hết hàng. Vui lòng chọn sản phẩm khác.'];
            }
            if ((float) $user['balance'] < (float) $variant['price']) {
                return ['message' => 'Số dư vừa thay đổi. Vui lòng thử lại.'];
            }

            $orderCode = Helpers::orderCode();
            $db->execute("INSERT INTO orders (order_code, user_id, variant_id, amount, payment_method, status) VALUES (?, ?, ?, ?, 'wallet', 'processing')", [$orderCode, $userId, $variant['id'], $variant['price']]);
            $orderId = $db->lastInsertId();
            $db->execute('UPDATE users SET balance = balance - ? WHERE id = ?', [$variant['price'], $userId]);
            $db->execute('UPDATE stocks SET is_sold = 1, order_id = ?, sold_at = NOW() WHERE id = ?', [$orderId, $stock['id']]);
            $db->execute("UPDATE orders SET stock_content = ?, status = 'completed', paid_at = NOW(), completed_at = NOW() WHERE id = ?", [$stock['content'], $orderId]);
            $db->execute("INSERT INTO transactions (user_id, type, amount, reference_type, reference_id, note) VALUES (?, 'purchase', ?, 'order', ?, ?)", [$userId, -((float) $variant['price']), $orderId, $orderCode]);

            return ['message' => "<blockquote>MUA HÀNG THÀNH CÔNG</blockquote>\n\nMã đơn: <code>" . $orderCode . "</code>\nSản phẩm: " . Helpers::escape($variant['name']) . "\n\nThông tin nhận:\n<code>" . Helpers::escape($stock['content']) . '</code>'];
        });
    }

    private function uniqueCode(): string
    {
        do {
            $code = Helpers::randomCode();
        } while ($this->db->fetch('SELECT id FROM orders WHERE payment_code = ?', [$code]) !== null);
        return $code;
    }
}
