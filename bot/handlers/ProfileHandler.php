<?php

declare(strict_types=1);

final class ProfileHandler
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function show(int $chatId, int $messageId, array $user, Language $lang): void
    {
        $count = $this->db->fetch("SELECT COUNT(*) total FROM orders WHERE user_id = ? AND status = 'completed'", [$user['id']]);
        $text = '<blockquote><b>' . $lang->get('profile_title') . "</b></blockquote>\n\n"
            . $lang->get('id') . ': <code>' . $user['telegram_id'] . "</code>\n"
            . $lang->get('name') . ': ' . Helpers::escape($user['first_name']) . "\n"
            . $lang->get('balance') . ': <b>' . Helpers::money($user['balance']) . "</b>\n"
            . $lang->get('purchased_orders') . ': ' . (int) $count['total'];
        $keyboard = [[['text' => $lang->get('order_history_button'), 'callback_data' => 'history']], [['text' => $lang->get('back'), 'callback_data' => 'start']]];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function history(int $chatId, int $messageId, int $userId, Language $lang): void
    {
        $orders = $this->db->fetchAll("SELECT o.order_code, o.amount, o.stock_content, o.created_at, v.name FROM orders o JOIN variants v ON v.id = o.variant_id WHERE o.user_id = ? AND o.status = 'completed' ORDER BY o.id DESC LIMIT 10", [$userId]);
        $text = '<blockquote><b>' . $lang->get('history_title') . "</b></blockquote>\n";
        foreach ($orders as $order) {
            $text .= "\n<b>" . Helpers::escape($order['name']) . '</b> · ' . Helpers::money($order['amount']) . "\n<code>" . Helpers::escape($order['stock_content']) . "</code>\n";
        }
        if ($orders === []) $text .= "\n" . $lang->get('empty_orders');
        $this->telegram->editMessage($chatId, $messageId, $text, [[['text' => $lang->get('back'), 'callback_data' => 'profile']]]);
    }
}
