<?php

declare(strict_types=1);

final class SupportHandler
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function show(int $chatId, int $messageId): void
    {
        $row = $this->db->fetch("SELECT key_value FROM settings WHERE key_name = 'support_text'");
        $text = "<b>HỖ TRỢ</b>\n\n" . Helpers::escape($row['key_value'] ?? 'Liên hệ quản trị viên.');
        $this->telegram->editMessage($chatId, $messageId, $text, [[['text' => '⬅ Quay lại', 'callback_data' => 'start']]]);
    }
}
