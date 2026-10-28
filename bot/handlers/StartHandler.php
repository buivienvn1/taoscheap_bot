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

        $shopName = ($settings['shop_name'] ?? '') ?: 'Taos Cheap';
        $shopIntro = ($settings['shop_intro'] ?? '') ?: "<b>Taos Cheap</b> - kho sản phẩm số\nđáp ứng mọi nhu cầu\nhằng ngày của bạn.";
        $owner = ($settings['owner_username'] ?? '') ?: '@taoscat';
        $channel = ($settings['channel_username'] ?? '') ?: '@taoscheap';
        $website = ($settings['website'] ?? '') ?: 'taoscheap.qthang.net';

        $text = '<blockquote>Chào mừng đến với <b>'
            . Helpers::escape($shopName)
            . "</b></blockquote>\n\n"
            . Helpers::escape($shopIntro) . "\n\n"
            . "<b>Tại sao chọn chúng tôi?</b>\n"
            . "- giao dịch an toàn ✅\n"
            . "- hỗ trợ nhanh chóng ✅\n"
            . "- sản phẩm chất lượng ✅\n\n"
            . "<b>Về chúng tôi:</b>\n"
            . 'owner - ' . Helpers::escape($owner) . "\n"
            . 'channel - ' . Helpers::escape($channel);

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
