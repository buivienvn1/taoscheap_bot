<?php

declare(strict_types=1);

final class StartHandler
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function show(int $chatId, ?int $messageId = null): void
    {
        $settings = $this->settings();
        $text = '<b>CHÀO MỪNG ĐẾN VỚI ' . Helpers::escape($settings['shop_name'] ?? 'SHOP') . "</b>\n\n"
            . Helpers::escape($settings['shop_intro'] ?? '') . "\n\n"
            . "• Giao dịch an toàn ✅\n• Hỗ trợ nhanh ✅\n• Sản phẩm chất lượng ✅";
        $contacts = array_filter([$settings['owner_username'] ?? '', $settings['channel_username'] ?? '', $settings['website'] ?? '']);
        if ($contacts !== []) {
            $text .= "\n\n" . Helpers::escape(implode(' · ', $contacts));
        }
        $keyboard = [
            [['text' => '📦 Sản phẩm', 'callback_data' => 'products'], ['text' => '💳 Nạp tiền', 'callback_data' => 'deposit']],
            [['text' => '👤 Cá nhân', 'callback_data' => 'profile'], ['text' => '🆘 Hỗ trợ', 'callback_data' => 'support']],
        ];

        $messageId === null
            ? $this->telegram->sendMessage($chatId, $text, $keyboard)
            : $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function settings(): array
    {
        $rows = $this->db->fetchAll('SELECT key_name, key_value FROM settings');
        return array_column($rows, 'key_value', 'key_name');
    }
}
