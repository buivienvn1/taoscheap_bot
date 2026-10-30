<?php

declare(strict_types=1);

final class ReferralService
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function config(): array
    {
        $row = $this->db->fetch("SELECT key_value FROM settings WHERE key_name='referral_config'");
        return self::decodeConfig($row['key_value'] ?? '');
    }

    private static function decodeConfig(string $json): array
    {
        $decoded = json_decode($json, true);
        return (is_array($decoded) ? $decoded : []) + [
            'enabled' => false, 'reward' => 0, 'daily_limit' => 0, 'daily_budget' => 0,
            'channel_id' => '', 'channel_url' => '', 'bot_username' => '', 'instructions' => '',
        ];
    }

    // Called only inside the transaction which inserts a genuinely new user.
    public function attach(int $inviteeId, string $code, bool $isBot): void
    {
        if ($isBot || !preg_match('/^[A-Za-z0-9]{12}$/D', $code)) return;
        $row = $this->db->fetch("SELECT key_value FROM settings WHERE key_name='referral_config' FOR UPDATE");
        $config = self::decodeConfig($row['key_value'] ?? '');
        if (!$config['enabled']) return;
        $inviter = $this->db->fetch('SELECT user_id FROM referral_codes WHERE code=?', [$code]);
        if ($inviter === null || (int) $inviter['user_id'] === $inviteeId) return;
        $this->db->execute(
            'INSERT INTO referrals(inviter_id,invitee_id,reward_amount,channel_id) VALUES(?,?,?,?)',
            [$inviter['user_id'], $inviteeId, $config['reward'], $config['channel_id']]
        );
    }

    public function code(int $userId): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $existing = $this->db->fetch('SELECT code FROM referral_codes WHERE user_id=?', [$userId]);
            if ($existing !== null) return (string) $existing['code'];
            $code = strtoupper(bin2hex(random_bytes(6)));
            try {
                $this->db->execute('INSERT INTO referral_codes(user_id,code) VALUES(?,?)', [$userId, $code]);
                return $code;
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) !== 1062) throw $error;
            }
        }
        throw new RuntimeException('Referral code allocation failed.');
    }

    public function needsGate(int $userId): bool
    {
        return $this->config()['enabled'] && $this->db->fetch(
            'SELECT id FROM referrals WHERE invitee_id=? AND gate_passed_at IS NULL', [$userId]
        ) !== null;
    }

    public static function isMember(array $member): bool
    {
        return in_array($member['status'] ?? '', ['creator', 'administrator', 'member'], true)
            || (($member['status'] ?? '') === 'restricted' && ($member['is_member'] ?? false) === true);
    }

    public function checkGate(int $userId, int $telegramId): string
    {
        $claimed = $this->db->execute(
            'UPDATE referrals SET last_gate_check_at=NOW() WHERE invitee_id=? AND gate_passed_at IS NULL
             AND (last_gate_check_at IS NULL OR last_gate_check_at<=DATE_SUB(NOW(),INTERVAL 5 SECOND))', [$userId]
        );
        if ($claimed !== 1) return 'referral_check_wait';
        $referral = $this->db->fetch('SELECT channel_id FROM referrals WHERE invitee_id=?', [$userId]);
        try {
            $member = $this->telegram->getChatMember((string) $referral['channel_id'], $telegramId);
            if (!self::isMember($member)) return 'referral_not_member';
        } catch (Throwable) {
            return 'referral_check_error';
        }
        $this->db->execute('UPDATE referrals SET gate_passed_at=NOW() WHERE invitee_id=? AND gate_passed_at IS NULL', [$userId]);
        return 'referral_gate_success';
    }

    // Network work happens before the final order transaction. API failures never prevent delivery.
    public function inspectOrder(int $userId): ?array
    {
        if (!$this->config()['enabled']) return null;
        $referral = $this->db->fetch(
            "SELECT r.id,r.channel_id,u.telegram_id FROM referrals r JOIN users u ON u.id=r.invitee_id
             WHERE r.invitee_id=? AND r.status IN ('waiting','verification')", [$userId]
        );
        if ($referral === null) return null;
        $result = 'api_error';
        try {
            $member = $this->telegram->getChatMember((string) $referral['channel_id'], (int) $referral['telegram_id']);
            $result = self::isMember($member) ? 'member' : 'not_member';
        } catch (Throwable $error) {
            error_log('Referral membership check failed: ' . $error::class);
        }
        return ['referral_id' => (int) $referral['id'], 'result' => $result, 'checked_at' => date('Y-m-d H:i:s')];
    }

    // Called in the same transaction which marks an order completed.
    public function recordOrder(int $orderId, ?array $evidence): void
    {
        if ($evidence === null) return;
        $referral = $this->db->fetch('SELECT * FROM referrals WHERE id=? FOR UPDATE', [$evidence['referral_id']]);
        if ($referral === null || in_array($referral['status'], ['queued', 'paid'], true)) return;
        $order = $this->db->fetch('SELECT user_id,status,amount,wallet_amount FROM orders WHERE id=?', [$orderId]);
        if ($order === null || $order['status'] !== 'completed' || (int) $order['user_id'] !== (int) $referral['invitee_id']) return;
        $paidAmount = (float) $order['amount'] + (float) $order['wallet_amount'];
        if ($paidAmount <= 0) return;
        $reason = ['member' => 'member_at_completion', 'not_member' => 'not_member', 'api_error' => 'membership_unavailable'][$evidence['result']];
        $this->db->execute(
            'INSERT IGNORE INTO referral_order_checks(referral_id,order_id,result,checked_at,reason) VALUES(?,?,?,?,?)',
            [$referral['id'], $orderId, $evidence['result'], $evidence['checked_at'], $reason]
        );
        if ($evidence['result'] === 'member') {
            $this->db->execute("UPDATE referrals SET status='queued',eligible_order_id=? WHERE id=?", [$orderId, $referral['id']]);
        } elseif ($evidence['result'] === 'api_error') {
            $this->db->execute("UPDATE referrals SET status='verification' WHERE id=?", [$referral['id']]);
        }
    }

    public function approveException(int $checkId, string $reason, string $admin): bool
    {
        if ($reason === '' || mb_strlen($reason) > 500) return false;
        return $this->db->transaction(function () use ($checkId, $reason, $admin): bool {
            $check = $this->db->fetch('SELECT * FROM referral_order_checks WHERE id=?', [$checkId]);
            if ($check === null) return false;
            $referral = $this->db->fetch('SELECT * FROM referrals WHERE id=? FOR UPDATE', [$check['referral_id']]);
            $check = $this->db->fetch('SELECT * FROM referral_order_checks WHERE id=? FOR UPDATE', [$checkId]);
            $order = $this->db->fetch('SELECT status FROM orders WHERE id=?', [$check['order_id']]);
            if ($check['result'] !== 'api_error' || $check['reviewed_at'] !== null || $order['status'] !== 'completed'
                || !in_array($referral['status'], ['waiting', 'verification'], true)) return false;
            $this->db->execute(
                "UPDATE referral_order_checks SET result='approved',review_reason=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?",
                [$reason, substr($admin, 0, 100), $checkId]
            );
            $this->db->execute("UPDATE referrals SET status='queued',eligible_order_id=? WHERE id=?", [$check['order_id'], $referral['id']]);
            return true;
        });
    }

    public function pay(int $referralId): bool
    {
        return $this->db->transaction(function () use ($referralId): bool {
            $referral = $this->db->fetch('SELECT * FROM referrals WHERE id=? FOR UPDATE', [$referralId]);
            if ($referral === null || $referral['status'] !== 'queued') return false;
            $config = $this->config(); // Already qualified rewards remain payable while disabled.
            $day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
            foreach ([0, (int) $referral['inviter_id']] as $scope) {
                $this->db->execute('INSERT IGNORE INTO referral_daily_usage(usage_day,inviter_id) VALUES(?,?)', [$day, $scope]);
            }
            $global = $this->db->fetch('SELECT * FROM referral_daily_usage WHERE usage_day=? AND inviter_id=0 FOR UPDATE', [$day]);
            $personal = $this->db->fetch('SELECT * FROM referral_daily_usage WHERE usage_day=? AND inviter_id=? FOR UPDATE', [$day, $referral['inviter_id']]);
            $reward = (int) $referral['reward_amount'];
            if ($reward < 1 || (int) $personal['reward_count'] >= (int) $config['daily_limit']
                || (int) $global['reward_amount'] + $reward > (int) $config['daily_budget']) return false;
            $user = $this->db->fetch('SELECT balance FROM users WHERE id=? FOR UPDATE', [$referral['inviter_id']]);
            // SQL DECIMAL arithmetic preserves any existing fractional wallet balance.
            $this->db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$reward, $referral['inviter_id']]);
            $after = $this->db->fetch('SELECT balance FROM users WHERE id=?', [$referral['inviter_id']]);
            $code = 'REF-' . $referral['id'];
            $this->db->execute(
                "INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,reference_type,reference_id,reference_code,note)
                 VALUES(?,'referral_reward',?,?,?,'referral',?,?,?)",
                [$referral['inviter_id'], $reward, $user['balance'], $after['balance'], $referral['id'], $code, 'referral_purchase_reward']
            );
            $transactionId = $this->db->lastInsertId();
            $this->db->execute("UPDATE referrals SET status='paid',transaction_id=?,paid_at=NOW() WHERE id=?", [$transactionId, $referral['id']]);
            foreach ([0, (int) $referral['inviter_id']] as $scope) {
                $this->db->execute('UPDATE referral_daily_usage SET reward_count=reward_count+1,reward_amount=reward_amount+? WHERE usage_day=? AND inviter_id=?', [$reward, $day, $scope]);
            }
            (new NotificationOutbox($this->db, $this->telegram))->enqueue((int) $referral['inviter_id'], 'referral:' . $referral['id'], 'referral_reward_received', [
                'reward' => Helpers::money($reward), 'balance' => Helpers::money($after['balance']), 'code' => $code,
            ]);
            return true;
        });
    }

    public function payBatch(int $limit = 100): int
    {
        $paid = 0;
        $config = $this->config();
        $day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        // Skip inviters already at their limit so they cannot starve later queued rewards.
        $rows = $this->db->fetchAll(
            "SELECT r.id FROM referrals r LEFT JOIN referral_daily_usage d ON d.usage_day=? AND d.inviter_id=r.inviter_id
             WHERE r.status='queued' AND COALESCE(d.reward_count,0)<? AND r.reward_amount<=?
             ORDER BY r.id LIMIT " . max(1, min(500, $limit)), [$day, $config['daily_limit'], $config['daily_budget']]
        );
        foreach ($rows as $row) {
            try {
                if ($this->pay((int) $row['id'])) $paid++;
            } catch (Throwable $error) {
                error_log('Referral payout failed [id=' . (int) $row['id'] . ']: ' . $error::class);
            }
        }
        return $paid;
    }

    public static function instructions(string $raw, Language $lang, int $reward): string
    {
        $text = Helpers::extractMultilingualText($raw, $lang->code());
        if (trim($text) === '') $text = $lang->get('referral_instructions_default');
        // Expand only in text nodes, never inside an href or other HTML attribute.
        $parts = preg_split('/(<[^>]*>)/u', Helpers::sanitizeTelegramHtml($text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parts as &$part) {
            if (!str_starts_with($part, '<')) $part = str_replace('{reward}', Helpers::escape(Helpers::money($reward)), $part);
        }
        return implode('', $parts);
    }

    public function saveConfig(array $input): void
    {
        $config = [
            'enabled' => !empty($input['enabled']),
            'reward' => self::positiveInteger($input['reward'] ?? ''),
            'daily_limit' => self::positiveInteger($input['daily_limit'] ?? ''),
            'daily_budget' => self::positiveInteger($input['daily_budget'] ?? ''),
            'channel_id' => trim((string) ($input['channel_id'] ?? '')),
            'channel_url' => trim((string) ($input['channel_url'] ?? '')),
            'instructions' => trim((string) ($input['instructions'] ?? '')),
            'bot_username' => $this->config()['bot_username'],
        ];
        if (mb_strlen($config['instructions']) > 2400) throw new InvalidArgumentException('referral_invalid_instructions');
        foreach (['vi', 'en'] as $code) {
            $rendered = self::instructions($config['instructions'], new Language($code), $config['reward']);
            if (mb_strlen($rendered) > 2800 || !self::validHtml($rendered)) throw new InvalidArgumentException('referral_invalid_instructions');
        }
        if ($config['enabled']) {
            if ($config['reward'] < 1 || $config['daily_limit'] < 1 || $config['daily_budget'] < $config['reward']
                || !preg_match('/^(?:-100\d+|@[A-Za-z][A-Za-z0-9_]{3,})$/D', $config['channel_id'])
                || !preg_match('~^https://t\.me/(?:[A-Za-z0-9_]+|\+[A-Za-z0-9_-]+)$~D', $config['channel_url'])) {
                throw new InvalidArgumentException('referral_invalid_config');
            }
            try {
                $bot = $this->telegram->getMe();
                $member = $this->telegram->getChatMember($config['channel_id'], (int) $bot['id']);
                if (!in_array($member['status'] ?? '', ['administrator', 'creator'], true)) {
                    throw new InvalidArgumentException('referral_channel_bot_not_admin');
                }
                $config['bot_username'] = (string) $bot['username'];
            } catch (InvalidArgumentException $error) {
                throw $error;
            } catch (Throwable $error) {
                $message = strtolower($error->getMessage());
                $notAccessible = str_contains($message, 'chat not found')
                    || str_contains($message, 'bot is not a member')
                    || str_contains($message, 'user not found');
                error_log(sprintf(
                    'Referral channel validation failed [channel=%s error=%s]',
                    $config['channel_id'],
                    $error->getMessage()
                ));
                throw new InvalidArgumentException($notAccessible
                    ? 'referral_channel_not_accessible'
                    : 'referral_channel_error');
            }
        }
        $this->db->transaction(function () use ($config): void {
            $old = $this->db->fetch("SELECT key_value FROM settings WHERE key_name='referral_config' FOR UPDATE");
            $oldConfig = self::decodeConfig($old['key_value'] ?? '');
            if ($config['channel_id'] !== $oldConfig['channel_id']
                && $this->db->fetch("SELECT id FROM referrals WHERE status<>'paid' LIMIT 1") !== null) {
                throw new InvalidArgumentException('referral_channel_locked');
            }
            $this->db->execute("UPDATE settings SET key_value=? WHERE key_name='referral_config'", [json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        });
    }

    private static function positiveInteger(mixed $value): int
    {
        $text = trim((string) $value);
        if (!preg_match('/^\d{1,12}$/D', $text)) throw new InvalidArgumentException('referral_invalid_config');
        return (int) $text;
    }

    public static function validHtml(string $html): bool
    {
        preg_match_all('~<(/?)([a-z-]+)\b[^>]*>~i', $html, $matches, PREG_SET_ORDER);
        $stack = [];
        foreach ($matches as $tag) {
            if ($tag[1] === '') $stack[] = strtolower($tag[2]);
            elseif (array_pop($stack) !== strtolower($tag[2])) return false;
        }
        return $stack === [];
    }
}
