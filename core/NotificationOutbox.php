<?php

declare(strict_types=1);

final class NotificationOutbox
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function enqueue(int $userId, string $eventKey, string $messageKey, array $params): int
    {
        $user = $this->db->fetch('SELECT telegram_id,language FROM users WHERE id=?', [$userId]);
        if ($user === null) {
            throw new RuntimeException('Notification user not found.');
        }

        return $this->enqueueRecipient(
            $userId,
            'user',
            (int) $user['telegram_id'],
            in_array($user['language'], ['vi', 'en'], true) ? (string) $user['language'] : 'vi',
            $eventKey,
            $messageKey,
            $params
        );
    }

    public function enqueueAdmin(int $chatId, string $eventKey, string $messageKey, array $params): int
    {
        return $this->enqueueRecipient(null, 'admin', $chatId, 'vi', $eventKey, $messageKey, $params);
    }

    public function enqueueRecipient(
        ?int $userId,
        string $recipientType,
        int $chatId,
        string $language,
        string $eventKey,
        string $messageKey,
        array $params,
        string $actionType = 'send',
        ?int $targetChatId = null,
        ?int $targetMessageId = null
    ): int {
        $this->db->execute(
            "INSERT INTO notification_outbox
             (user_id,recipient_type,telegram_chat_id,language,action_type,target_chat_id,target_message_id,event_key,message_key,message_params,status)
             VALUES(?,?,?,?,?,?,?,?,?,?,'pending')",
            [
                $userId,
                $recipientType === 'admin' ? 'admin' : 'user',
                $chatId,
                in_array($language, ['vi', 'en'], true) ? $language : 'vi',
                $actionType === 'edit_or_send' ? 'edit_or_send' : 'send',
                $targetChatId,
                $targetMessageId,
                $eventKey,
                $messageKey,
                json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
        return $this->db->lastInsertId();
    }

    public function deliver(int $outboxId): bool
    {
        $claimed = $this->db->execute(
            "UPDATE notification_outbox SET status='processing',attempts=attempts+1,locked_at=NOW()
             WHERE id=? AND (status IN ('pending','failed') OR (status='processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))
               AND attempts < 10",
            [$outboxId]
        );
        if ($claimed !== 1) return false;

        $item = $this->db->fetch(
            'SELECT n.*,u.telegram_id user_telegram_id,u.language user_language
             FROM notification_outbox n LEFT JOIN users u ON u.id=n.user_id WHERE n.id=?',
            [$outboxId]
        );
        if ($item === null) return false;

        $chatId = (int) ($item['telegram_chat_id'] ?: $item['user_telegram_id']);
        $language = (string) ($item['language'] ?: $item['user_language'] ?: 'vi');
        if ($chatId === 0) {
            $this->markFailed($outboxId, 'recipient_missing');
            return false;
        }

        $lang = new Language(in_array($language, ['vi', 'en'], true) ? $language : 'vi');
        $params = json_decode((string) $item['message_params'], true) ?: [];
        $text = $this->render($lang, (string) $item['message_key'], $params);
        $keyboard = $this->keyboard($lang, (string) $item['message_key']);

        try {
            $result = null;
            if ($item['action_type'] === 'edit_or_send' && (int) $item['target_message_id'] > 0) {
                $result = $this->telegram->editMessageOrSend(
                    (int) ($item['target_chat_id'] ?: $chatId),
                    (int) $item['target_message_id'],
                    $text,
                    $keyboard
                );
            } else {
                $result = $this->telegram->sendMessage($chatId, $text, $keyboard);
            }
            $sentMessageId = (int) ($result['result']['message_id'] ?? $item['target_message_id'] ?? 0);
            $this->db->execute(
                "UPDATE notification_outbox SET status='sent',sent_at=NOW(),sent_message_id=?,locked_at=NULL,last_error_code=NULL WHERE id=?",
                [$sentMessageId > 0 ? $sentMessageId : null, $outboxId]
            );
            $this->cleanupPaymentMessages($item, $sentMessageId);
            return true;
        } catch (Throwable $error) {
            $this->markFailed($outboxId, substr($error::class, 0, 100));
            error_log('Payment notification failed: ' . $error::class);
            return false;
        }
    }

    public function retryBatch(int $limit = 20): int
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->fetchAll(
            "SELECT id FROM notification_outbox
             WHERE (status IN ('pending','failed') OR (status='processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))
               AND attempts < 10 AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY id LIMIT " . $limit
        );
        $sent = 0;
        foreach ($rows as $row) {
            if ($this->deliver((int) $row['id'])) $sent++;
        }
        return $sent;
    }

    public function retryCleanupBatch(int $limit = 20): int
    {
        $limit = max(1, min(100, $limit));
        $links = $this->db->fetchAll(
            "SELECT * FROM payment_message_links
             WHERE is_active=1 AND cleanup_status='failed' AND cleanup_attempts < 10
             ORDER BY id LIMIT " . $limit
        );
        $cleaned = 0;
        foreach ($links as $link) {
            try {
                $this->telegram->deleteMessage((int) $link['chat_id'], (int) $link['message_id']);
                $this->db->execute(
                    "UPDATE payment_message_links SET is_active=0,cleanup_status='cleaned',cleanup_attempts=cleanup_attempts+1,cleaned_at=NOW(),last_error_code=NULL WHERE id=? AND is_active=1",
                    [$link['id']]
                );
                $cleaned++;
            } catch (Throwable $error) {
                $this->db->execute(
                    "UPDATE payment_message_links SET cleanup_attempts=cleanup_attempts+1,last_error_code=? WHERE id=?",
                    [substr($error::class, 0, 100), $link['id']]
                );
            }
        }
        return $cleaned;
    }

    private function render(Language $lang, string $messageKey, array $params): string
    {
        $escaped = [];
        foreach ($params as $key => $value) {
            if (!is_array($value)) $escaped[$key] = Helpers::escape((string) $value);
        }
        $text = $lang->get($messageKey, $escaped);
        if ($messageKey === 'order_result_success') {
            if (($params['extra'] ?? '') !== '') {
                $text .= "\n" . $lang->get('order_extra_wallet', ['amount' => Helpers::escape((string) $params['extra'])]);
            }
            foreach (($params['deliveries'] ?? []) as $index => $content) {
                $safe = mb_substr((string) $content, 0, 3000);
                $text .= "\n\n<b>" . $lang->get('delivery_item', ['number' => $index + 1]) . ":</b>\n<code>" . Helpers::escape($safe) . '</code>';
            }
        }
        return mb_substr($text, 0, 4000);
    }

    private function keyboard(Language $lang, string $messageKey): array
    {
        if ($messageKey !== 'deposit_success_detailed') return [];
        return [[
            ['text' => $lang->get('btn_profile'), 'callback_data' => 'profile'],
            ['text' => $lang->get('home'), 'callback_data' => 'start'],
        ]];
    }

    private function cleanupPaymentMessages(array $item, int $sentMessageId): void
    {
        $params = json_decode((string) $item['message_params'], true) ?: [];
        $requestType = (string) ($params['cleanup_request_type'] ?? '');
        $requestId = (int) ($params['cleanup_request_id'] ?? 0);
        if ($requestId < 1 || !in_array($requestType, ['deposit', 'order'], true)) return;

        $links = $this->db->fetchAll(
            "SELECT * FROM payment_message_links
             WHERE request_type=? AND request_id=? AND is_active=1 AND cleanup_status IN ('pending','failed') ORDER BY id",
            [$requestType, $requestId]
        );
        foreach ($links as $link) {
            if ($item['action_type'] === 'edit_or_send'
                && $sentMessageId === (int) $item['target_message_id']
                && (int) $link['chat_id'] === (int) $item['target_chat_id']
                && (int) $link['message_id'] === (int) $item['target_message_id']) {
                $this->db->execute(
                    "UPDATE payment_message_links SET is_active=0,cleanup_status='cleaned',cleaned_at=NOW(),last_error_code=NULL WHERE id=?",
                    [$link['id']]
                );
                continue;
            }
            try {
                $this->telegram->deleteMessage((int) $link['chat_id'], (int) $link['message_id']);
                $this->db->execute(
                    "UPDATE payment_message_links SET is_active=0,cleanup_status='cleaned',cleanup_attempts=cleanup_attempts+1,cleaned_at=NOW(),last_error_code=NULL WHERE id=?",
                    [$link['id']]
                );
            } catch (Throwable $error) {
                $this->db->execute(
                    "UPDATE payment_message_links SET cleanup_status='failed',cleanup_attempts=cleanup_attempts+1,last_error_code=? WHERE id=?",
                    [substr($error::class, 0, 100), $link['id']]
                );
            }
        }
    }

    private function markFailed(int $outboxId, string $code): void
    {
        $this->db->execute(
            "UPDATE notification_outbox SET status='failed',locked_at=NULL,last_error_code=?,next_attempt_at=DATE_ADD(NOW(), INTERVAL LEAST(60, attempts * attempts) MINUTE) WHERE id=?",
            [$code, $outboxId]
        );
    }
}
