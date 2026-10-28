<?php

declare(strict_types=1);

final class Router
{
    private StartHandler $start;
    private ProductHandler $products;
    private DepositHandler $deposit;
    private ProfileHandler $profile;
    private SupportHandler $support;
    private OrderHandler $orders;

    public function __construct(private Database $db, private Telegram $telegram, VietQR $vietQr, array $app)
    {
        $this->start = new StartHandler($db, $telegram);
        $this->products = new ProductHandler($db, $telegram);
        $this->deposit = new DepositHandler($db, $telegram, $vietQr, (int) $app['min_deposit']);
        $this->profile = new ProfileHandler($db, $telegram);
        $this->support = new SupportHandler($db, $telegram);
        $this->orders = new OrderHandler($db, $telegram, $vietQr);
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }
        $message = $update['message'] ?? null;
        if (!is_array($message) || !isset($message['chat']['id'], $message['from'])) return;

        $user = $this->upsertUser($message['from']);
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '/start' || str_starts_with($text, '/start ')) {
            $this->start->show((int) $message['chat']['id']);
            return;
        }
        $this->telegram->sendMessage((int) $message['chat']['id'], 'Dùng menu bên dưới để thao tác.', [[['text' => '🏠 Mở menu', 'callback_data' => 'start']]]);
    }

    private function handleCallback(array $callback): void
    {
        $this->telegram->answerCallback((string) $callback['id']);
        $message = $callback['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId === 0 || $messageId === 0) return;

        $user = $this->upsertUser($callback['from']);
        $data = (string) ($callback['data'] ?? 'start');
        if ($data === 'start') $this->start->show($chatId, $messageId);
        elseif ($data === 'products') $this->products->list($chatId, $messageId);
        elseif ($data === 'deposit') $this->deposit->show($chatId, (int) $user['id']);
        elseif ($data === 'profile') $this->profile->show($chatId, $messageId, $user);
        elseif ($data === 'history') $this->profile->history($chatId, $messageId, (int) $user['id']);
        elseif ($data === 'support') $this->support->show($chatId, $messageId);
        elseif (str_starts_with($data, 'product:')) $this->products->detail($chatId, $messageId, (int) substr($data, 8));
        elseif (str_starts_with($data, 'buy:')) $this->orders->buy($chatId, $user, (int) substr($data, 4));
    }

    private function upsertUser(array $from): array
    {
        $this->db->execute(
            'INSERT INTO users (telegram_id, username, first_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name)',
            [(int) $from['id'], $from['username'] ?? null, $from['first_name'] ?? '']
        );
        return $this->db->fetch('SELECT * FROM users WHERE telegram_id = ?', [(int) $from['id']]);
    }
}
