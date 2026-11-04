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
    private ReferralService $referrals;
    private ReferralHandler $referralHandler;

    public function __construct(
        private Database $db,
        private Telegram $telegram,
        VietQR $vietQr,
        array $app,
        SupplierCatalogService $supplierCatalog,
        private ?BinancePayService $binancePay = null
    ) {
        global $stockFulfillment;
        $this->referrals = new ReferralService($db, $telegram);
        $this->referralHandler = new ReferralHandler($db, $telegram, $this->referrals);
        $this->start = new StartHandler($db, $telegram);
        $this->products = new ProductHandler($db, $telegram, $supplierCatalog, $this->binancePay);
        $this->deposit = new DepositHandler($db, $telegram, $vietQr, $this->binancePay);
        $this->profile = new ProfileHandler($db, $telegram);
        $this->support = new SupportHandler($db, $telegram);
        $this->orders = new OrderHandler($db, $telegram, $vietQr, $app, $stockFulfillment, $supplierCatalog, $this->referrals, $this->binancePay);
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

        if (($message['chat']['type'] ?? '') !== 'private' || !empty($message['from']['is_bot'])) return;
        $text = trim((string) ($message['text'] ?? ''));
        $code = preg_match('/^\/start\s+([A-Za-z0-9]{12})$/D', $text, $match) ? $match[1] : '';
        $user = $this->upsertUser($message['from'], $code);
        $lang = $this->language($user['language'] ?? 'vi');
        if ($this->referrals->needsGate((int) $user['id'])) {
            $this->referralHandler->gate((int) $message['chat']['id'], null, $lang, $this->fullName($user));
            return;
        }
        if ($text === '/start' || str_starts_with($text, '/start ')) {
            $this->start->show((int) $message['chat']['id'], $lang);
            return;
        }
        if ($this->binancePay !== null && preg_match('/^[0-9]{8,64}$/', $text) === 1) {
            $this->claimBinanceOrderId(
                (int) $message['chat']['id'],
                (int) ($message['message_id'] ?? 0),
                (int) $user['id'],
                $text,
                $lang
            );
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
        if (($message['chat']['type'] ?? '') !== 'private' || !isset($callback['from']['id'])
            || $chatId !== (int) $callback['from']['id']) return;

        $user = $this->findUser($callback['from']);
        $data = (string) ($callback['data'] ?? 'start');
        $lockName = 'telegram_callback_user_' . (int) $user['id'];
        $lock = $this->db->fetch('SELECT GET_LOCK(?,0) acquired', [$lockName]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) return;
        register_shutdown_function(function () use ($lockName): void {
            try {
                $this->db->fetch('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (Throwable) {
                // The database connection also releases named locks when the request ends.
            }
        });
        $this->deactivatePaymentMessage($chatId, $messageId);
        if (str_starts_with($data, 'lang:')) {
            $code = substr($data, 5);
            if (isset($this->languages[$code])) {
                $this->db->execute('UPDATE users SET language = ? WHERE id = ?', [$code, $user['id']]);
                $user['language'] = $code;
            }
        }
        $lang = $this->language($user['language'] ?? 'vi');

        if ($this->referrals->needsGate((int) $user['id']) && $data !== 'language' && $data !== 'support') {
            $notice = null;
            if ($data === 'referral_check') $notice = $this->referrals->checkGate((int) $user['id'], (int) $user['telegram_id']);
            if ($this->referrals->needsGate((int) $user['id'])) {
                $this->referralHandler->gate($chatId, $messageId, $lang, $this->fullName($user), $notice);
                return;
            }
            $this->start->show($chatId, $lang, $messageId);
            return;
        }

        if ($data === 'start' || str_starts_with($data, 'lang:')) $this->start->show($chatId, $lang, $messageId);
        elseif ($data === 'referral') $this->referralHandler->show($chatId, $messageId, (int) $user['id'], $lang);
        elseif ($data === 'referral_check') $this->start->show($chatId, $lang, $messageId);
        elseif ($data === 'language') $this->showLanguage($chatId, $messageId, $lang);
        elseif ($data === 'products') $this->products->list($chatId, $messageId, (int) $user['id'], $lang);
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
        elseif (str_starts_with($data, 'checkout_method:')) {
            $parts = explode(':', $data, 4);
            if (count($parts) === 4) {
                $this->orders->checkout($chatId, $messageId, $user, (int) $parts[1], (int) $parts[2], $lang, $parts[3]);
            }
        }
        elseif (str_starts_with($data, 'checkout:')) {
            $parts = explode(':', $data, 3);
            if (count($parts) === 3) {
                $this->orders->checkout($chatId, $messageId, $user, (int) $parts[1], (int) $parts[2], $lang);
            }
        }
        elseif (str_starts_with($data, 'cancel_order:')) {
            $this->orders->cancelOrder($chatId, $messageId, (int) $user['id'], (int) substr($data, 13), $lang);
        }
        elseif ($data === 'deposit') $this->deposit->show($chatId, $messageId, (int) $user['id'], $lang);
        elseif ($data === 'deposit:vietqr') $this->deposit->showBank($chatId, $messageId, (int) $user['id'], $lang);
        elseif ($data === 'deposit:binance') $this->deposit->showBinance($chatId, $messageId, (int) $user['id'], $lang);
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

    private function fullName(array $user): string
    {
        $fullName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        return $fullName !== '' ? $fullName : (string) ($user['username'] ?? '');
    }

    private function claimBinanceOrderId(int $chatId, int $inputMessageId, int $userId, string $orderId, Language $lang): void
    {
        if ($inputMessageId > 0) {
            try {
                $this->telegram->deleteMessage($chatId, $inputMessageId);
            } catch (Throwable $error) {
                error_log('Binance Order ID message cleanup failed: ' . $error::class);
            }
        }

        try {
            $registration = $this->binancePay?->registerPendingClaim($userId, $chatId, $orderId) ?? ['result' => 'disabled'];
        } catch (Throwable $error) {
            error_log('Binance Pay claim registration failed: ' . $error::class . ': ' . $error->getMessage());
            $registration = ['result' => 'unavailable'];
        }
        if (!in_array($registration['result'] ?? '', ['queued', 'already_queued'], true)) {
            $this->sendBinanceClaimResult($chatId, null, $orderId, $registration, $lang);
            return;
        }

        $claimId = (int) $registration['claim_id'];
        $messageId = $registration['message_id'] === null ? null : (int) $registration['message_id'];
        $messageId = $this->renderBinanceProgress($chatId, $messageId, $lang->get('binance_verifying', [
            'order_id' => Helpers::escape($orderId),
        ]), []);
        if ($messageId > 0) {
            $this->binancePay?->attachClaimMessage($claimId, $chatId, $messageId);
            $this->recordBinanceProgressMessage($registration, $userId, $chatId, $messageId);
        }

        try {
            $result = $this->binancePay?->checkPendingClaim($claimId) ?? ['result' => 'disabled'];
        } catch (Throwable $error) {
            error_log('Binance Pay fast claim failed: ' . $error::class . ': ' . $error->getMessage());
            return;
        }
        if (($result['result'] ?? '') === 'claimed') {
            $outboxId = (int) ($result['outbox_id'] ?? 0);
            if ($outboxId > 0) (new NotificationOutbox($this->db, $this->telegram))->deliver($outboxId);
            return;
        }
        if (!in_array($result['result'] ?? '', ['not_found', 'processing', 'processing_failed'], true)) {
            $this->sendBinanceClaimResult($chatId, $messageId, $orderId, $result, $lang);
        }
    }

    private function renderBinanceProgress(int $chatId, ?int $messageId, string $text, array $keyboard): int
    {
        $result = $messageId === null
            ? $this->telegram->sendMessage($chatId, $text, $keyboard)
            : $this->telegram->editMessageOrSend($chatId, $messageId, $text, $keyboard);
        return (int) ($result['result']['message_id'] ?? $messageId ?? 0);
    }

    private function recordBinanceProgressMessage(array $registration, int $userId, int $chatId, int $messageId): void
    {
        $requestType = (string) $registration['request_type'];
        $requestId = (int) $registration['request_id'];
        $this->db->transaction(function (Database $db) use ($requestType, $requestId, $userId, $chatId, $messageId): void {
            $db->execute(
                "UPDATE payment_message_links SET is_active=0,cleanup_status='superseded' WHERE request_type=? AND request_id=? AND is_active=1 AND NOT (chat_id=? AND message_id=?)",
                [$requestType, $requestId, $chatId, $messageId]
            );
            $db->execute(
                "INSERT INTO payment_message_links(request_type,request_id,user_id,chat_id,message_id,message_type,qr_url)
                 VALUES(?,?,?,?,?,'text','') ON DUPLICATE KEY UPDATE request_type=VALUES(request_type),request_id=VALUES(request_id),user_id=VALUES(user_id),message_type='text',qr_url='',is_active=1,cleanup_status='pending',cleanup_attempts=0,last_error_code=NULL,cleaned_at=NULL",
                [$requestType, $requestId, $userId, $chatId, $messageId]
            );
        });
    }

    private function sendBinanceClaimResult(int $chatId, ?int $messageId, string $orderId, array $result, Language $lang): void
    {
        $key = match ($result['result'] ?? '') {
            'already_claimed' => 'binance_claim_already_used',
            'short_payment' => 'binance_claim_short_payment',
            'no_pending_request' => 'binance_claim_no_pending',
            'invalid_order_id' => 'binance_claim_invalid',
            'expired' => 'binance_claim_expired',
            default => 'binance_claim_unavailable',
        };
        $text = $lang->get($key, [
            'order_id' => Helpers::escape($orderId),
            'amount_usdt' => Helpers::escape(Helpers::cleanNumber((string) ($result['amount_usdt'] ?? '0'), 8)),
            'amount_vnd' => Helpers::money((string) ($result['amount_vnd'] ?? '0')),
        ]);
        if ($messageId === null) {
            $this->telegram->sendMessage($chatId, $text, [[['text' => $lang->get('main_menu'), 'callback_data' => 'start']]]);
            return;
        }
        $this->telegram->editMessageOrSend($chatId, $messageId, $text, [[['text' => $lang->get('main_menu'), 'callback_data' => 'start']]]);
    }

    private function upsertUser(array $from, string $referralCode = ''): array
    {
        return $this->db->transaction(function () use ($from, $referralCode): array {
            $telegramId = (int) ($from['id'] ?? 0);
            $created = $this->db->execute(
                'INSERT INTO users(telegram_id) VALUES(?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$telegramId]
            ) === 1;
            $rawData = json_encode($from, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $this->db->execute(
                'UPDATE users SET username=?,first_name=?,last_name=?,language_code=?,is_premium=?,is_bot=?,raw_data=? WHERE telegram_id=?',
                [
                    $from['username'] ?? null,
                    $from['first_name'] ?? '',
                    $from['last_name'] ?? null,
                    $from['language_code'] ?? null,
                    !empty($from['is_premium']) ? 1 : 0,
                    !empty($from['is_bot']) ? 1 : 0,
                    $rawData === false ? null : $rawData,
                    $telegramId,
                ]
            );
            $user = $this->db->fetch('SELECT * FROM users WHERE telegram_id = ?', [$telegramId]);
            if ($created) $this->referrals->attach((int) $user['id'], $referralCode, !empty($from['is_bot']));
            return $user;
        });
    }
}
