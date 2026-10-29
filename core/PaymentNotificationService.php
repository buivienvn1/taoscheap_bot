<?php

declare(strict_types=1);

final class PaymentNotificationService
{
    public function __construct(
        private Database $db,
        private Telegram $telegram,
    ) {
    }

    public function enqueue(Database $db, int $userId, string $eventKey, string $messageKey, array $params): int
    {
        $encoded = json_encode(
            $params,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode notification parameters');
        }

        $db->execute(
            "INSERT INTO notification_outbox(user_id,event_key,message_key,message_params,status) VALUES(?,?,?,?,'pending')",
            [$userId, $eventKey, $messageKey, $encoded]
        );

        return $db->lastInsertId();
    }

    public function deliver(int $outboxId): bool
    {
        $claimed = $this->db->execute(
            "UPDATE notification_outbox
             SET status='processing',attempts=attempts+1,last_attempt_at=NOW()
             WHERE id=? AND status IN ('pending','failed') AND attempts < 10",
            [$outboxId]
        );
        if ($claimed !== 1) {
            return false;
        }

        $item = $this->db->fetch(
            'SELECT n.*,u.telegram_id,u.language
             FROM notification_outbox n
             JOIN users u ON u.id=n.user_id
             WHERE n.id=?',
            [$outboxId]
        );
        if ($item === null) {
            return false;
        }

        try {
            $this->telegram->sendMessage((int) $item['telegram_id'], $this->render($item));
            $this->db->execute(
                "UPDATE notification_outbox SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=? AND status='processing'",
                [$outboxId]
            );
            return true;
        } catch (Throwable $error) {
            $this->db->execute(
                "UPDATE notification_outbox SET status='failed',last_error=? WHERE id=? AND status='processing'",
                [substr($error::class, 0, 190), $outboxId]
            );
            error_log('Payment notification failed: ' . $error::class);
            return false;
        }
    }

    public function deliverPending(int $limit = 20): int
    {
        $limit = max(1, min(100, $limit));
        $this->db->execute(
            "UPDATE notification_outbox SET status='failed',last_error='worker_timeout'
             WHERE status='processing' AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
        $items = $this->db->fetchAll(
            "SELECT id FROM notification_outbox
             WHERE status IN ('pending','failed') AND attempts < 10
             ORDER BY id LIMIT " . $limit
        );

        $sent = 0;
        foreach ($items as $item) {
            if ($this->deliver((int) $item['id'])) {
                $sent++;
            }
        }

        return $sent;
    }

    private function render(array $item): string
    {
        $language = in_array($item['language'], ['vi', 'en'], true) ? $item['language'] : 'vi';
        $lang = new Language($language);
        $params = json_decode((string) $item['message_params'], true);
        if (!is_array($params)) {
            $params = [];
        }

        $textParams = array_filter($params, static fn (mixed $value): bool => !is_array($value));
        $text = $lang->get((string) $item['message_key'], $textParams);
        if ($item['message_key'] !== 'order_result_success') {
            return $text;
        }

        if (($params['extra'] ?? '') !== '') {
            $text .= "\n" . $lang->get('order_extra_wallet', ['amount' => (string) $params['extra']]);
        }
        foreach (($params['deliveries'] ?? []) as $index => $content) {
            $text .= "\n\n<b>" . $lang->get('delivery_item', ['number' => $index + 1])
                . ":</b>\n<code>" . Helpers::escape((string) $content) . '</code>';
        }

        return $text;
    }
}
