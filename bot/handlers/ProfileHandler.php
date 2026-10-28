<?php

declare(strict_types=1);

final class ProfileHandler
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function show(int $chatId, int $messageId, array $user): void
    {
        $count = $this->db->fetch("SELECT COUNT(*) total FROM orders WHERE user_id = ? AND status = 'completed'", [$user['id']]);
        $text = "<b>CÁ NHÂN</b>\n\nID: <code>" . $user['telegram_id'] . "</code>\nTên: " . Helpers::escape($user['first_name']) . "\nSố dư: <b>" . Helpers::money($user['balance']) . "</b>\nĐơn đã mua: " . (int) $count['total'];
        $keyboard = [[['text' => '📜 Lịch sử mua hàng', 'callback_data' => 'history']], [['text' => '⬅ Quay lại', 'callback_data' => 'start']]];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function history(int $chatId, int $messageId, int $userId): void
    {
        $orders = $this->db->fetchAll("SELECT o.order_code, o.amount, o.stock_content, o.created_at, v.name FROM orders o JOIN variants v ON v.id = o.variant_id WHERE o.user_id = ? AND o.status = 'completed' ORDER BY o.id DESC LIMIT 10", [$userId]);
        $text = "<b>10 ĐƠN GẦN NHẤT</b>\n";
        foreach ($orders as $order) {
            $text .= "\n<b>" . Helpers::escape($order['name']) . '</b> · ' . Helpers::money($order['amount']) . "\n<code>" . Helpers::escape($order['stock_content']) . "</code>\n";
        }
        if ($orders === []) $text .= "\nChưa có đơn hàng.";
        $this->telegram->editMessage($chatId, $messageId, $text, [[['text' => '⬅ Quay lại', 'callback_data' => 'profile']]]);
    }
}
