<?php

declare(strict_types=1);

final class StartHandler
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function show(int $chatId, Language $lang, ?int $messageId = null): void
    {
        $settings = $this->settings();

        $shopName = ($settings['shop_name'] ?? '') ?: 'Taos Cheap';
        $introKey = $lang->code() === 'en' ? 'shop_intro_en' : 'shop_intro';
        $defaultIntro = $lang->code() === 'en'
            ? "<b>Taos Cheap</b> - digital products for your daily needs."
            : "<b>Taos Cheap</b> - kho sản phẩm số\nđáp ứng mọi nhu cầu\nhằng ngày của bạn.";
        $shopIntro = ($settings[$introKey] ?? '') ?: $defaultIntro;
        $owner = ($settings['owner_username'] ?? '') ?: '@taoscat';
        $channel = ($settings['channel_username'] ?? '') ?: '@taoscheap';
        $website = ($settings['website'] ?? '') ?: 'taoscheap.qthang.net';

        $text = '<blockquote><b>' . $lang->get('welcome_title', ['shop' => Helpers::escape($shopName)]) . "</b></blockquote>\n\n"
            . Helpers::sanitizeTelegramHtml($shopIntro) . "\n\n"
            . $lang->get('why_us') . "\n"
            . $lang->get('safe_transactions') . "\n"
            . $lang->get('fast_support') . "\n"
            . $lang->get('quality_products') . "\n\n"
            . $lang->get('about_us') . "\n"
            . $lang->get('owner') . ' - ' . Helpers::escape($owner) . "\n"
            . $lang->get('channel') . ' - ' . Helpers::escape($channel);

        $keyboard = [
            [['text' => $lang->get('btn_products'), 'callback_data' => 'products'], ['text' => $lang->get('btn_deposit'), 'callback_data' => 'deposit']],
            [['text' => $lang->get('btn_profile'), 'callback_data' => 'profile'], ['text' => $lang->get('btn_support'), 'callback_data' => 'support']],
            [['text' => $lang->get('btn_language'), 'callback_data' => 'language']],
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
