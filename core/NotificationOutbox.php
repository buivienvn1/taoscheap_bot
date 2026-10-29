<?php

declare(strict_types=1);

final class NotificationOutbox
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function enqueue(int $userId, string $eventKey, string $messageKey, array $params): int
    {
        $this->db->execute(
            "INSERT INTO notification_outbox(user_id,event_key,message_key,message_params,status) VALUES(?,?,?,?,'pending')",
            [$userId, $eventKey, $messageKey, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]
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

        $item = $this->db->fetch('SELECT n.*,u.telegram_id,u.language FROM notification_outbox n JOIN users u ON u.id=n.user_id WHERE n.id=?', [$outboxId]);
        if ($item === null) return false;
        $lang = new Language(in_array($item['language'], ['vi', 'en'], true) ? $item['language'] : 'vi');
        $params = json_decode((string) $item['message_params'], true) ?: [];
        $scalars = [];
        foreach ($params as $key => $value) {
            if (!is_array($value)) $scalars[$key] = Helpers::escape((string) $value);
        }
        $text = $lang->get((string) $item['message_key'], $scalars);
        if ($item['message_key'] === 'order_result_success') {
            if (($params['extra'] ?? '') !== '') $text .= "\n" . $lang->get('order_extra_wallet', ['amount' => Helpers::escape((string) $params['extra'])]);
            foreach (($params['deliveries'] ?? []) as $index => $content) {
                $text .= "\n\n<b>" . $lang->get('delivery_item', ['number' => $index + 1]) . ":</b>\n<code>" . Helpers::escape((string) $content) . '</code>';
            }
        }
        try {
            $this->telegram->sendMessage((int) $item['telegram_id'], $text);
            $this->db->execute("UPDATE notification_outbox SET status='sent',sent_at=NOW(),locked_at=NULL,last_error_code=NULL WHERE id=?", [$outboxId]);
            return true;
        } catch (Throwable $error) {
            $this->db->execute(
                "UPDATE notification_outbox SET status='failed',locked_at=NULL,last_error_code=?,next_attempt_at=DATE_ADD(NOW(), INTERVAL LEAST(60, attempts * attempts) MINUTE) WHERE id=?",
                [substr($error::class, 0, 100), $outboxId]
            );
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
}
