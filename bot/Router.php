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
    private array $languages;

    public function __construct(private Database $db, private Telegram $telegram, VietQR $vietQr, array $app)
    {
        $this->start = new StartHandler($db, $telegram);
        $this->products = new ProductHandler($db, $telegram);
        $this->deposit = new DepositHandler($db, $telegram, $vietQr, (int) $app['min_deposit']);
        $this->profile = new ProfileHandler($db, $telegram);
        $this->support = new SupportHandler($db, $telegram);
        $this->orders = new OrderHandler($db, $telegram, $vietQr);
        $this->languages = ['vi' => new Language('vi'), 'en' => new Language('en')];
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
        $lang = $this->language($user['language'] ?? 'vi');
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '/start' || str_starts_with($text, '/start ')) {
            $this->start->show((int) $message['chat']['id'], $lang);
            return;
        }
        $this->telegram->sendMessage((int) $message['chat']['id'], $lang->get('use_menu'), [[['text' => $lang->get('open_menu'), 'callback_data' => 'start']]]);
    }

    private function handleCallback(array $callback): void
    {
        $message = $callback['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId === 0 || $messageId === 0) return;

        $user = $this->findUser($callback['from']);
        $data = (string) ($callback['data'] ?? 'start');
        if (str_starts_with($data, 'lang:')) {
            $code = substr($data, 5);
            if (isset($this->languages[$code])) {
                $this->db->execute('UPDATE users SET language = ? WHERE id = ?', [$code, $user['id']]);
                $user['language'] = $code;
            }
        }
        $lang = $this->language($user['language'] ?? 'vi');

        if ($data === 'start' || str_starts_with($data, 'lang:')) $this->start->show($chatId, $lang, $messageId);
        elseif ($data === 'language') $this->showLanguage($chatId, $messageId, $lang);
        elseif ($data === 'products') $this->products->list($chatId, $messageId, $lang);
        elseif ($data === 'support') $this->support->show($chatId, $messageId, $lang);
        elseif (str_starts_with($data, 'product:')) $this->products->detail($chatId, $messageId, (int) $user['id'], (int) substr($data, 8), $lang);
        elseif (str_starts_with($data, 'qty:')) {
            $parts = explode(':', $data, 4);
            if (count($parts) === 4) {
                $this->products->changeQuantity($chatId, $messageId, (int) $user['id'], (int) $parts[1], $parts[3], (int) $parts[2], $lang);
            }
        }
        elseif (str_starts_with($data, 'checkout:')) {
            $parts = explode(':', $data, 3);
            if (count($parts) === 3) {
                $this->orders->checkout($chatId, $messageId, $user, (int) $parts[1], (int) $parts[2], $lang);
            }
        }
        elseif ($data === 'deposit') $this->deposit->show($chatId, $messageId, (int) $user['id'], $lang);
        elseif ($data === 'profile') $this->profile->show($chatId, $messageId, $user, $lang);
        elseif (str_starts_with($data, 'history')) {
            $parts = explode(':', $data, 2);
            $this->profile->history($chatId, $messageId, (int) $user['id'], $lang, isset($parts[1]) ? max(1, (int) $parts[1]) : 1);
        }
        elseif (str_starts_with($data, 'buy:')) $this->orders->legacyBuy($chatId, $messageId, (int) $user['id'], (int) substr($data, 4), $lang);
    }

    private function showLanguage(int $chatId, int $messageId, Language $lang): void
    {
        $text = '<blockquote><b>' . $lang->get('language_title') . "</b></blockquote>\n\n" . $lang->get('language_prompt');
        $keyboard = [
            [['text' => '🇻🇳 Tiếng Việt', 'callback_data' => 'lang:vi'], ['text' => '🇬🇧 English', 'callback_data' => 'lang:en']],
            [['text' => $lang->get('back'), 'callback_data' => 'start']],
        ];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    private function language(string $code): Language
    {
        return $this->languages[$code] ?? $this->languages['vi'];
    }

    private function findUser(array $from): array
    {
        $user = $this->db->fetch('SELECT * FROM users WHERE telegram_id = ?', [(int) $from['id']]);
        return $user ?? $this->upsertUser($from);
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
