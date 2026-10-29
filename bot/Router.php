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
        $this->deposit = new DepositHandler($db, $telegram, $vietQr);
        $this->profile = new ProfileHandler($db, $telegram);
        $this->support = new SupportHandler($db, $telegram);
        $this->orders = new OrderHandler($db, $telegram, $vietQr, $app);
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
        $callbackId = (string) ($callback['id'] ?? '');
        if ($callbackId !== '') {
            try {
                $this->telegram->answerCallback($callbackId);
            } catch (Throwable $error) {
                error_log('Telegram callback acknowledgement failed: ' . $error::class);
            }
        }

        $message = $callback['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($chatId === 0 || $messageId === 0) return;

        $user = $this->findUser($callback['from']);
        $data = (string) ($callback['data'] ?? 'start');
        $this->deactivatePaymentMessage($chatId, $messageId);
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
        elseif (str_starts_with($data, 'notify_stock:')) {
            $this->products->subscribeToStock($chatId, $messageId, (int) $user['id'], (int) substr($data, 13), $lang);
        }
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
        elseif (str_starts_with($data, 'transactions:')) {
            $this->profile->transactions($chatId, $messageId, (int) $user['id'], $lang, max(1, (int) substr($data, 13)));
        }
        elseif (str_starts_with($data, 'transaction:')) {
            $parts = explode(':', $data, 3);
            if (count($parts) === 3) {
                $this->profile->transactionDetail($chatId, $messageId, (int) $user['id'], (int) $parts[1], max(1, (int) $parts[2]), $lang);
            }
        }
        elseif (str_starts_with($data, 'orders:') || str_starts_with($data, 'history:')) {
            $parts = explode(':', $data, 2);
            $this->profile->orders($chatId, $messageId, (int) $user['id'], $lang, isset($parts[1]) ? max(1, (int) $parts[1]) : 1);
        }
        elseif (str_starts_with($data, 'order_history:')) {
            $parts = explode(':', $data, 3);
            if (count($parts) === 3) {
                $this->profile->orderDetail($chatId, $messageId, (int) $user['id'], (int) $parts[1], max(1, (int) $parts[2]), $lang);
            }
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

    private function deactivatePaymentMessage(int $chatId, int $messageId): void
    {
        $this->db->execute(
            "UPDATE payment_message_links SET is_active=0,cleanup_status='superseded'
             WHERE chat_id=? AND message_id=? AND is_active=1 AND cleanup_status IN ('pending','failed')",
            [$chatId, $messageId]
        );
    }

    private function findUser(array $from): array
    {
        return $this->upsertUser($from);
    }

    private function upsertUser(array $from): array
    {
        $telegramId = (int) ($from['id'] ?? 0);
        $rawData = json_encode($from, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->db->execute(
            'INSERT INTO users (telegram_id,username,first_name,last_name,language_code,is_premium,is_bot,raw_data)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                username=VALUES(username),
                first_name=VALUES(first_name),
                last_name=VALUES(last_name),
                language_code=VALUES(language_code),
                is_premium=VALUES(is_premium),
                is_bot=VALUES(is_bot),
                raw_data=VALUES(raw_data)',
            [
                $telegramId,
                $from['username'] ?? null,
                $from['first_name'] ?? '',
                $from['last_name'] ?? null,
                $from['language_code'] ?? null,
                !empty($from['is_premium']) ? 1 : 0,
                !empty($from['is_bot']) ? 1 : 0,
                $rawData === false ? null : $rawData,
            ]
        );
        return $this->db->fetch('SELECT * FROM users WHERE telegram_id = ?', [$telegramId]);
    }
}
