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
        $fullName = trim((string) $user['first_name'] . ' ' . (string) ($user['last_name'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));
        $usernameText = $username === '' ? $lang->get('username_empty') : '@' . Helpers::escape($username);
        $joined = Helpers::dateTime((string) ($user['created_at'] ?? ''));
        if ($joined === '') $joined = $lang->get('joined_unknown');
        $premiumLine = !empty($user['is_premium']) ? "\n⭐ " . $lang->get('telegram_premium') : '';
        $text = '<blockquote><b>' . $lang->get('profile_title') . "</b></blockquote>\n\n"
            . $lang->get('id') . ': <code>' . (int) $user['telegram_id'] . "</code>\n"
            . $lang->get('name') . ': ' . Helpers::escape($fullName) . "\n"
            . $lang->get('username') . ': ' . $usernameText
            . $premiumLine . "\n"
            . $lang->get('joined_since') . ': ' . Helpers::escape($joined) . "\n"
            . $lang->get('balance') . ': <b>' . Helpers::money($user['balance']) . "</b>\n"
            . $lang->get('purchased_orders') . ': ' . (int) $count['total'];
        $keyboard = [
            [['text' => $lang->get('transaction_history_button'), 'callback_data' => 'transactions:1']],
            [['text' => $lang->get('order_history_button'), 'callback_data' => 'orders:1']],
            [['text' => $lang->get('back'), 'callback_data' => 'start']],
        ];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function transactions(int $chatId, int $messageId, int $userId, Language $lang, int $page = 1): void
    {
        $page = max(1, $page);
        $rows = $this->db->fetchAll(
            'SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT ' . (self::HISTORY_PAGE_SIZE + 1)
            . ' OFFSET ' . (($page - 1) * self::HISTORY_PAGE_SIZE),
            [$userId]
        );
        $hasNext = count($rows) > self::HISTORY_PAGE_SIZE;
        $rows = array_slice($rows, 0, self::HISTORY_PAGE_SIZE);
        $text = '<blockquote><b>' . $lang->get('transaction_history_title') . '</b></blockquote>';
        $keyboard = [];
        foreach ($rows as $row) {
            $type = $lang->get('transaction_type_' . $row['type']);
            $code = trim((string) ($row['reference_code'] ?? '')) ?: ('#' . (int) $row['id']);
            $text .= "\n\n" . $lang->get('history_transaction_summary', [
                'code' => Helpers::escape($code),
                'type' => $type,
                'amount' => Helpers::money($row['amount']),
            ]);
            $keyboard[] = [[
                'text' => $lang->get('history_detail_button', ['code' => $code]),
                'callback_data' => 'transaction:' . (int) $row['id'] . ':' . $page,
            ]];
        }
        if ($rows === []) $text .= "\n\n" . $lang->get('empty_transactions');
        $this->appendNavigation($keyboard, 'transactions', $page, $hasNext, $lang);
        $keyboard[] = [['text' => $lang->get('back'), 'callback_data' => 'profile']];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function transactionDetail(int $chatId, int $messageId, int $userId, int $transactionId, int $page, Language $lang): void
    {
        $row = $this->db->fetch(
            'SELECT t.*,d.code transfer_code FROM transactions t LEFT JOIN deposits d ON t.reference_type=\'deposit\' AND d.id=t.reference_id WHERE t.id=? AND t.user_id=?',
            [$transactionId, $userId]
        );
        if ($row === null) {
            $this->notFound($chatId, $messageId, 'transactions:' . max(1, $page), $lang);
            return;
        }
        $code = trim((string) ($row['reference_code'] ?? '')) ?: ('#' . (int) $row['id']);
        $text = '<blockquote><b>' . $lang->get('history_detail_title') . "</b></blockquote>\n\n"
            . 'Code: <code>' . Helpers::escape($code) . "</code>\n"
            . $lang->get('transaction_status') . ': ' . $lang->get('transaction_type_' . $row['type']) . "\n"
            . $lang->get('amount') . ': <b>' . Helpers::money($row['amount']) . "</b>\n"
            . $lang->get('transaction_time') . ': ' . Helpers::escape(Helpers::dateTime((string) $row['created_at']));
        if ($row['balance_before'] !== null) $text .= "\n" . $lang->get('transaction_balance_before') . ': ' . Helpers::money($row['balance_before']);
        if ($row['balance_after'] !== null) $text .= "\n" . $lang->get('transaction_balance_after') . ': ' . Helpers::money($row['balance_after']);
        if (trim((string) ($row['transfer_code'] ?? '')) !== '') $text .= "\n" . $lang->get('transaction_transfer_code') . ': <code>' . Helpers::escape($row['transfer_code']) . '</code>';
        if (trim((string) ($row['note'] ?? '')) !== '') $text .= "\n" . $lang->get('transaction_note') . ': ' . Helpers::escape($row['note']);
        $this->telegram->editMessage($chatId, $messageId, $text, [[
            ['text' => $lang->get('back'), 'callback_data' => 'transactions:' . max(1, $page)],
        ]]);
    }

    public function orders(int $chatId, int $messageId, int $userId, Language $lang, int $page = 1): void
    {
        $page = max(1, $page);
        $rows = $this->db->fetchAll(
            'SELECT id,order_code,product_name FROM orders WHERE user_id=? ORDER BY id DESC LIMIT '
            . (self::HISTORY_PAGE_SIZE + 1) . ' OFFSET ' . (($page - 1) * self::HISTORY_PAGE_SIZE),
            [$userId]
        );
        $hasNext = count($rows) > self::HISTORY_PAGE_SIZE;
        $rows = array_slice($rows, 0, self::HISTORY_PAGE_SIZE);
        $text = '<blockquote><b>' . $lang->get('history_title') . '</b></blockquote>';
        $keyboard = [];
        foreach ($rows as $row) {
            $text .= "\n\n" . $lang->get('history_order_summary', [
                'code' => Helpers::escape($row['order_code']),
                'product' => Helpers::escape($row['product_name'] ?: '-'),
            ]);
            $keyboard[] = [[
                'text' => $lang->get('history_detail_button', ['code' => $row['order_code']]),
                'callback_data' => 'order_history:' . (int) $row['id'] . ':' . $page,
            ]];
        }
        if ($rows === []) $text .= "\n\n" . $lang->get('empty_orders');
        $this->appendNavigation($keyboard, 'orders', $page, $hasNext, $lang);
        $keyboard[] = [['text' => $lang->get('back'), 'callback_data' => 'profile']];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function orderDetail(int $chatId, int $messageId, int $userId, int $orderId, int $page, Language $lang): void
    {
        $order = $this->db->fetch('SELECT * FROM orders WHERE id=? AND user_id=?', [$orderId, $userId]);
        if ($order === null) {
            $this->notFound($chatId, $messageId, 'orders:' . max(1, $page), $lang);
            return;
        }
        $statusKey = 'order_status_' . $order['status'];
        $time = Helpers::dateTime((string) ($order['completed_at'] ?: $order['created_at']));
        $text = '<blockquote><b>' . $lang->get('order_history_detail_title') . "</b></blockquote>\n\n"
            . 'Code: <code>' . Helpers::escape($order['order_code']) . "</code>\n"
            . $lang->get('transaction_status') . ': ' . $lang->get($statusKey) . "\n"
            . $lang->get('transaction_time') . ': ' . Helpers::escape($time) . "\n"
            . $lang->get('product') . ': ' . Helpers::escape($order['product_name'] ?: '-') . "\n"
            . $lang->get('quantity') . ': <b>' . (int) $order['quantity'] . "</b>\n"
            . $lang->get('unit_price') . ': ' . Helpers::money($order['unit_price']) . "\n"
            . $lang->get('total') . ': <b>' . Helpers::money((float) $order['amount'] + (float) $order['wallet_amount']) . "</b>\n"
            . $lang->get('payment_method') . ': ' . $lang->get('payment_method_' . $order['payment_method']);
        if ($order['payment_method'] === 'hybrid') {
            $text .= "\n" . $lang->get('payment_wallet_deducted_line', ['amount' => Helpers::money($order['wallet_amount'])]);
            $text .= "\n" . $lang->get('payment_need_transfer_line', ['amount' => Helpers::money($order['amount'])]);
        }
        if ((float) $order['wallet_credited'] > 0) $text .= "\n" . $lang->get('wallet_credit') . ': ' . Helpers::money($order['wallet_credited']);
        if ($order['status'] === 'completed') {
            $deliveries = $this->db->fetchAll('SELECT content FROM order_deliveries WHERE order_id=? ORDER BY id', [$orderId]);
            if ($deliveries === [] && trim((string) $order['stock_content']) !== '') $deliveries = [['content' => $order['stock_content']]];
            foreach ($deliveries as $index => $delivery) {
                $safeContent = Helpers::escape(mb_substr((string) $delivery['content'], 0, 3000));
                $section = "\n\n<b>" . $lang->get('delivery_item', ['number' => $index + 1]) . ":</b>\n<code>" . $safeContent . '</code>';
                if (mb_strlen($text . $section) > 4000) break;
                $text .= $section;
            }
        }
        $this->telegram->editMessage($chatId, $messageId, $text, [[
            ['text' => $lang->get('back'), 'callback_data' => 'orders:' . max(1, $page)],
        ]]);
    }

    private function appendNavigation(array &$keyboard, string $prefix, int $page, bool $hasNext, Language $lang): void
    {
        $navigation = [];
        if ($page > 1) $navigation[] = ['text' => $lang->get('history_previous'), 'callback_data' => $prefix . ':' . ($page - 1)];
        if ($hasNext) $navigation[] = ['text' => $lang->get('history_next'), 'callback_data' => $prefix . ':' . ($page + 1)];
        if ($navigation !== []) $keyboard[] = $navigation;
    }

    private function notFound(int $chatId, int $messageId, string $backCallback, Language $lang): void
    {
        $this->telegram->editMessage($chatId, $messageId, $lang->get('history_not_found'), [[
            ['text' => $lang->get('back'), 'callback_data' => $backCallback],
        ]]);
    }
}
