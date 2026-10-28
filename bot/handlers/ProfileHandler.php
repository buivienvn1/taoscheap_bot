<?php

declare(strict_types=1);

final class ProfileHandler
{
    private const HISTORY_PAGE_SIZE = 5;

    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function show(int $chatId, int $messageId, array $user, Language $lang): void
    {
        $count = $this->db->fetch("SELECT COUNT(*) total FROM orders WHERE user_id=? AND status='completed'", [$user['id']]);
        $text = '<blockquote><b>' . $lang->get('profile_title') . "</b></blockquote>\n\n"
            . $lang->get('id') . ': <code>' . (int) $user['telegram_id'] . "</code>\n"
            . $lang->get('name') . ': ' . Helpers::escape($user['first_name']) . "\n"
            . $lang->get('balance') . ': <b>' . Helpers::money($user['balance']) . "</b>\n"
            . $lang->get('purchased_orders') . ': ' . (int) $count['total'];
        $keyboard = [[['text' => $lang->get('order_history_button'), 'callback_data' => 'history:1']], [['text' => $lang->get('back'), 'callback_data' => 'start']]];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function history(int $chatId, int $messageId, int $userId, Language $lang, int $page = 1): void
    {
        $offset = ($page - 1) * self::HISTORY_PAGE_SIZE;
        $orders = $this->db->fetchAll(
            "SELECT o.* FROM orders o WHERE o.user_id=? AND o.status IN ('completed','failed','cancelled') ORDER BY o.id DESC LIMIT "
            . (self::HISTORY_PAGE_SIZE + 1) . ' OFFSET ' . $offset,
            [$userId]
        );
        $hasNext = count($orders) > self::HISTORY_PAGE_SIZE;
        $orders = array_slice($orders, 0, self::HISTORY_PAGE_SIZE);
        $text = '<blockquote><b>' . $lang->get('history_title') . "</b></blockquote>\n";
        foreach ($orders as $order) {
            $text .= "\n<b>" . $lang->get('history_order_line', [
                'product' => Helpers::escape($order['product_name'] ?: '-'),
                'quantity' => (int) $order['quantity'],
                'amount' => Helpers::money($order['amount']),
            ]) . "</b>\n<code>" . Helpers::escape($order['order_code']) . '</code>';
            if ((float) $order['wallet_credited'] > 0) {
                $reason = $order['status'] === 'completed' ? 'overpayment' : ($order['result_reason'] ?: 'overpayment');
                $reasonKey = 'history_reason_' . $reason;
                $text .= "\n" . $lang->get('history_wallet_line', [
                    'amount' => Helpers::money($order['wallet_credited']),
                    'reason' => $lang->get($reasonKey),
                ]);
            }
            if ($order['status'] === 'completed') {
                $deliveries = $this->db->fetchAll('SELECT content FROM order_deliveries WHERE order_id=? ORDER BY id', [$order['id']]);
                if ($deliveries === [] && trim((string) $order['stock_content']) !== '') {
                    $deliveries = [['content' => $order['stock_content']]];
                }
                foreach ($deliveries as $index => $delivery) {
                    $text .= "\n" . $lang->get('delivery_item', ['number' => $index + 1]) . ": <code>" . Helpers::escape($delivery['content']) . '</code>';
                }
            }
            $text .= "\n";
        }
        if ($orders === []) $text .= "\n" . $lang->get('empty_orders');
        $navigation = [];
        if ($page > 1) $navigation[] = ['text' => $lang->get('history_previous'), 'callback_data' => 'history:' . ($page - 1)];
        if ($hasNext) $navigation[] = ['text' => $lang->get('history_next'), 'callback_data' => 'history:' . ($page + 1)];
        $keyboard = $navigation === [] ? [] : [$navigation];
        $keyboard[] = [['text' => $lang->get('back'), 'callback_data' => 'profile']];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }
}
