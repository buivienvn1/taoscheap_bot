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
        $shopName = ($settings['shop_name'] ?? '') ?: 'Foxpay';
        $shopIntro = ($settings['shop_intro'] ?? '') ?: "FOXPAY - gudang aplikasi digital\nuntuk memenuhi semua kebutuhan\nharian anda.";
        $owner = ($settings['owner_username'] ?? '') ?: '@foxstoreid';
        $channel = ($settings['channel_username'] ?? '') ?: '@foxpayid';
        $website = ($settings['website'] ?? '') ?: 'foxpay.my.id';

        $text = '<blockquote>Selamat Datang Di ' . Helpers::escape($shopName) . "</blockquote>\n\n"
            . Helpers::escape($shopIntro) . "\n\n"
            . "Kenapa harus kami ?\n"
            . "- transaksi aman ✅\n"
            . "- cs fast respon ✅\n"
            . "- produk berkualitas ✅\n\n"
            . "Tentang kami :\n"
            . 'owner - ' . Helpers::escape($owner) . "\n"
            . 'channel - ' . Helpers::escape($channel) . "\n"
            . 'website - ' . Helpers::escape($website);
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
