<?php

declare(strict_types=1);

final class BinancePayService
{
    public function __construct(
        private Database $db,
        private BinancePayClient $client,
        private BinancePayAdapter $adapter,
        private PaymentProcessor $processor,
        private array $config = []
    ) {
    }

    public function isEnabled(): bool
    {
        return (string) ($this->setting('binance_pay_enabled') ?? '0') === '1'
            && trim((string) ($this->setting('binance_pay_id') ?? '')) !== '';
    }

    public function paymentDetails(): array
    {
        return [
            'id' => trim((string) ($this->setting('binance_pay_id') ?? '')),
            'rate' => $this->rate(),
        ];
    }

    public function registerPendingClaim(int $userId, int $chatId, string $orderId): array
    {
        $orderId = trim($orderId);
        if (preg_match('/^[0-9]{8,64}$/', $orderId) !== 1) return ['result' => 'invalid_order_id'];
        if (!$this->isEnabled()) return ['result' => 'disabled'];

        return $this->db->transaction(function (Database $db) use ($userId, $chatId, $orderId): array {
            $request = $this->pendingRequest($userId, true);
            if ($request === null) return ['result' => 'no_pending_request'];

            $transaction = $db->fetch('SELECT claimed_at FROM binance_pay_transactions WHERE binance_order_id=? FOR UPDATE', [$orderId]);
            if ($transaction !== null && $transaction['claimed_at'] !== null) return ['result' => 'already_claimed'];

            $existing = $db->fetch('SELECT * FROM binance_pending_claims WHERE binance_order_id=? FOR UPDATE', [$orderId]);
            if ($existing !== null) {
                $sameOwner = (int) $existing['user_id'] === $userId
                    && (string) $existing['request_type'] === (string) $request['request_type']
                    && (int) $existing['request_id'] === (int) $request['id'];
                if (!$sameOwner) return ['result' => 'already_claimed'];
                if ($existing['status'] === 'claimed') return ['result' => 'already_claimed'];
                if (in_array($existing['status'], ['pending', 'processing'], true)) {
                    return $this->claimRegistrationResult($existing, $request, true);
                }
                $db->execute(
                    "UPDATE binance_pending_claims SET chat_id=?,status='pending',attempts=0,last_error_code=NULL,last_checked_at=NULL,claimed_at=NULL,expires_at=DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id=?",
                    [$chatId, $existing['id']]
                );
                $existing['chat_id'] = $chatId;
                $existing['status'] = 'pending';
                return $this->claimRegistrationResult($existing, $request, true);
            }

            $db->execute(
                "UPDATE binance_pending_claims SET status='cancelled',last_error_code='superseded' WHERE request_type=? AND request_id=? AND status IN ('pending','processing')",
                [$request['request_type'], $request['id']]
            );
            $link = $db->fetch(
                "SELECT chat_id,message_id FROM payment_message_links WHERE request_type=? AND request_id=? AND message_type='text' AND is_active=1 ORDER BY id DESC LIMIT 1",
                [$request['request_type'], $request['id']]
            );
            $messageId = $link === null ? null : (int) $link['message_id'];
            $targetChatId = $link === null ? $chatId : (int) $link['chat_id'];
            $db->execute(
                "INSERT INTO binance_pending_claims(user_id,chat_id,message_id,binance_order_id,request_type,request_id,expires_at) VALUES(?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE))",
                [$userId, $targetChatId, $messageId, $orderId, $request['request_type'], $request['id']]
            );
            return [
                'result' => 'queued',
                'claim_id' => $db->lastInsertId(),
                'request_type' => (string) $request['request_type'],
                'request_id' => (int) $request['id'],
                'chat_id' => $targetChatId,
                'message_id' => $messageId,
                'existing' => false,
            ];
        });
    }

    public function attachClaimMessage(int $claimId, int $chatId, int $messageId): void
    {
        if ($claimId < 1 || $messageId < 1) return;
        $this->db->execute(
            "UPDATE binance_pending_claims SET chat_id=?,message_id=? WHERE id=? AND status IN ('pending','processing')",
            [$chatId, $messageId, $claimId]
        );
    }

    public function checkPendingClaim(int $claimId, bool $synchronize = true): array
    {
        if ($claimId < 1) return ['result' => 'invalid_order_id'];
        if ($synchronize) $this->sync();

        $claimed = $this->db->execute(
            "UPDATE binance_pending_claims SET status='processing',attempts=attempts+1,last_checked_at=NOW(),last_error_code=NULL
             WHERE id=? AND status='pending' AND expires_at>NOW()",
            [$claimId]
        );
        if ($claimed !== 1) {
            $row = $this->db->fetch('SELECT * FROM binance_pending_claims WHERE id=?', [$claimId]);
            if ($row === null) return ['result' => 'not_found'];
            if ($row['status'] === 'claimed') return ['result' => 'already_claimed'];
            if ($row['status'] === 'processing') return ['result' => 'processing'];
            return ['result' => (string) $row['status']];
        }

        $claim = $this->db->fetch('SELECT * FROM binance_pending_claims WHERE id=?', [$claimId]);
        if ($claim === null) return ['result' => 'not_found'];
        try {
            $result = $this->claimRegisteredRequest($claim);
        } catch (Throwable $error) {
            $this->releaseClaim($claimId, substr($error::class, 0, 100));
            throw $error;
        }

        $resultType = (string) ($result['result'] ?? 'processing_failed');
        if ($resultType === 'not_found' || $resultType === 'processing_failed') {
            $this->releaseClaim($claimId, $resultType);
        } elseif ($resultType === 'claimed') {
            $this->db->execute("UPDATE binance_pending_claims SET status='claimed',claimed_at=NOW(),last_error_code=NULL WHERE id=?", [$claimId]);
        } elseif ($resultType === 'short_payment') {
            $this->db->execute("UPDATE binance_pending_claims SET status='failed',last_error_code='short_payment' WHERE id=?", [$claimId]);
        } elseif ($resultType === 'already_claimed') {
            $this->db->execute("UPDATE binance_pending_claims SET status='failed',last_error_code='already_claimed' WHERE id=?", [$claimId]);
        } else {
            $this->releaseClaim($claimId, $resultType);
        }
        return $result + ['claim_id' => $claimId];
    }

    public function processPendingClaims(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $this->db->execute(
            "UPDATE binance_pending_claims SET status='pending',last_error_code='processing_timeout'
             WHERE status='processing' AND last_checked_at<DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
        );
        $expired = $this->db->execute(
            "UPDATE binance_pending_claims SET status='expired',last_error_code='expired'
             WHERE status IN ('pending','processing') AND expires_at<=NOW()"
        );
        $rows = $this->db->fetchAll(
            "SELECT id FROM binance_pending_claims WHERE status='pending' AND expires_at>NOW() ORDER BY last_checked_at IS NULL DESC,last_checked_at,id LIMIT " . $limit
        );
        if ($rows === []) return ['checked' => 0, 'claimed' => 0, 'expired' => $expired];

        $this->sync();
        $claimed = 0;
        $checked = 0;
        foreach ($rows as $row) {
            try {
                $result = $this->checkPendingClaim((int) $row['id'], false);
                $checked++;
                if (($result['result'] ?? '') === 'claimed') $claimed++;
            } catch (Throwable $error) {
                error_log('Binance pending claim failed: ' . $error::class . ': ' . $error->getMessage());
            }
        }
        return ['checked' => $checked, 'claimed' => $claimed, 'expired' => $expired];
    }

    public function claimOrderId(int $userId, string $orderId): array
    {
        $registration = $this->registerPendingClaim($userId, 0, $orderId);
        if (!in_array($registration['result'] ?? '', ['queued', 'already_queued'], true)) return $registration;
        return $this->checkPendingClaim((int) $registration['claim_id']);
    }

    private function claimRegisteredRequest(array $claim): array
    {
        $transaction = $this->db->fetch('SELECT * FROM binance_pay_transactions WHERE binance_order_id=?', [$claim['binance_order_id']]);
        if ($transaction === null) return ['result' => 'not_found'];
        if ($transaction['claimed_at'] !== null) return ['result' => 'already_claimed'];

        $request = $this->requestByIdentity((int) $claim['user_id'], (string) $claim['request_type'], (int) $claim['request_id']);
        if ($request === null) return ['result' => 'no_pending_request'];

        $amountVnd = number_format((float) $transaction['amount_usdt'] * $this->rate(), 2, '.', '');
        if ($request['request_type'] === 'order' && (float) $amountVnd < (float) $request['amount']) {
            return [
                'result' => 'short_payment',
                'amount_usdt' => (string) $transaction['amount_usdt'],
                'required_vnd' => (string) $request['amount'],
            ];
        }

        $result = $this->processor->process([
            'provider' => (string) ($this->config['provider'] ?? 'binance_pay_third_party'),
            'provider_event_id' => (string) $transaction['transaction_id'],
            'provider_transaction_id' => (string) $transaction['transaction_id'],
            'provider_transaction_number' => (string) ($transaction['transaction_number'] ?? ''),
            'binance_order_id' => (string) $transaction['binance_order_id'],
            'binance_usdt_amount' => (string) $transaction['amount_usdt'],
            'amount' => $amountVnd,
            'content' => (string) $request['payment_code'],
            'direction' => 'IN',
            'transaction_at' => (string) ($transaction['transaction_at'] ?? date('c')),
        ]);
        $processorResult = (string) ($result['result'] ?? '');
        if ($processorResult === 'duplicate_code') return ['result' => 'already_claimed'];
        if (!$this->isDefinitiveProcessorResult($processorResult)) return ['result' => 'processing_failed'];

        $claimed = $this->db->transaction(function (Database $db) use ($transaction, $claim, $request): bool {
            $updated = $db->execute(
                "UPDATE binance_pay_transactions SET claimed_by_user_id=?,claimed_request_type=?,claimed_request_id=?,claimed_at=NOW() WHERE id=? AND claimed_at IS NULL",
                [$claim['user_id'], $request['request_type'], $request['id'], $transaction['id']]
            );
            if ($updated !== 1) return false;
            $table = $request['request_type'] === 'order' ? 'orders' : 'deposits';
            $db->execute(
                "UPDATE {$table} SET binance_order_id=?,binance_usdt_amount=? WHERE id=?",
                [$transaction['binance_order_id'], $transaction['amount_usdt'], $request['id']]
            );
            return true;
        });
        if (!$claimed) return ['result' => 'already_claimed'];

        return $result + [
            'result' => 'claimed',
            'order_id' => (string) $transaction['binance_order_id'],
            'amount_usdt' => (string) $transaction['amount_usdt'],
            'amount_vnd' => $amountVnd,
        ];
    }

    private function pendingRequest(int $userId, bool $forUpdate = false): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $request = $this->db->fetch(
            "SELECT 'order' request_type,id,amount,payment_code FROM orders
             WHERE user_id=? AND payment_method='binance_pay' AND status='pending' AND binance_order_id IS NULL
             ORDER BY id DESC LIMIT 1" . $suffix,
            [$userId]
        );
        if ($request !== null) return $request;
        return $this->db->fetch(
            "SELECT 'deposit' request_type,id,0.00 amount,code payment_code FROM deposits
             WHERE user_id=? AND payment_method='binance_pay' AND status='pending' AND binance_order_id IS NULL
             ORDER BY id DESC LIMIT 1" . $suffix,
            [$userId]
        );
    }

    private function requestByIdentity(int $userId, string $type, int $requestId): ?array
    {
        if ($type === 'order') {
            return $this->db->fetch(
                "SELECT 'order' request_type,id,amount,payment_code FROM orders WHERE id=? AND user_id=? AND payment_method='binance_pay' AND status='pending' AND binance_order_id IS NULL",
                [$requestId, $userId]
            );
        }
        return $this->db->fetch(
            "SELECT 'deposit' request_type,id,0.00 amount,code payment_code FROM deposits WHERE id=? AND user_id=? AND payment_method='binance_pay' AND status='pending' AND binance_order_id IS NULL",
            [$requestId, $userId]
        );
    }

    private function claimRegistrationResult(array $claim, array $request, bool $existing): array
    {
        return [
            'result' => $existing ? 'already_queued' : 'queued',
            'claim_id' => (int) $claim['id'],
            'request_type' => (string) $request['request_type'],
            'request_id' => (int) $request['id'],
            'chat_id' => (int) $claim['chat_id'],
            'message_id' => $claim['message_id'] === null ? null : (int) $claim['message_id'],
            'existing' => $existing,
        ];
    }

    private function releaseClaim(int $claimId, string $error): void
    {
        $this->db->execute(
            "UPDATE binance_pending_claims SET status='pending',last_error_code=? WHERE id=? AND status='processing' AND expires_at>NOW()",
            [substr($error, 0, 100), $claimId]
        );
    }

    private function isDefinitiveProcessorResult(string $result): bool
    {
        return in_array($result, ['deposit_completed', 'order_delivered', 'closed_order_wallet'], true)
            || str_ends_with($result, '_wallet');
    }

    private function sync(): void
    {
        $feed = $this->adapter->adapt($this->client->fetchPage(), (string) ($this->config['provider'] ?? 'binance_pay_third_party'));
        if ($feed['stale'] || $feed['incomplete']) return;
        foreach ($feed['transactions'] as $transaction) {
            $this->db->execute(
                "INSERT INTO binance_pay_transactions(transaction_id,transaction_number,binance_order_id,status,amount_usdt,transaction_at,raw_payload)
                 VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE transaction_number=VALUES(transaction_number),status=VALUES(status),amount_usdt=VALUES(amount_usdt),transaction_at=VALUES(transaction_at),raw_payload=VALUES(raw_payload)",
                [
                    $transaction['transaction_id'],
                    $transaction['transaction_number'],
                    $transaction['order_id'],
                    $transaction['status'],
                    $transaction['amount_usdt'],
                    $transaction['transaction_at'],
                    json_encode($transaction['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private function rate(): float
    {
        $rate = (float) ($this->setting('binance_pay_rate_usdt_vnd') ?? 0);
        return $rate > 0 ? $rate : 25500.0;
    }

    private function setting(string $key): ?string
    {
        $row = $this->db->fetch('SELECT key_value FROM settings WHERE key_name=?', [$key]);
        return $row === null ? null : (string) $row['key_value'];
    }
}
