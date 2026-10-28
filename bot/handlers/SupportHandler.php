<?php

declare(strict_types=1);

final class SupportHandler
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function show(int $chatId, int $messageId, Language $lang): void
    {
        $key = $lang->code() === 'en' ? 'support_text_en' : 'support_text';
        $row = $this->db->fetch('SELECT key_value FROM settings WHERE key_name = ?', [$key]);
        $supportText = ($row['key_value'] ?? '') ?: $lang->get('support_default');
        $text = '<blockquote>' . $lang->get('support_title') . "</blockquote>\n\n" . Helpers::escape($supportText);
        $this->telegram->editMessage($chatId, $messageId, $text, [[['text' => $lang->get('back'), 'callback_data' => 'start']]]);
    }
}
