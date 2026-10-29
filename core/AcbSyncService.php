<?php

declare(strict_types=1);

final class AcbSyncService
{
    private const PROVIDER = 'acb';

    public function __construct(
        private Database $db,
        private AcbClient $client,
        private PaymentProcessor $processor,
        private array $config,
    ) {
    }

    public function run(): array
    {
        $account = trim((string) ($this->config['account_number'] ?? ''));
        if (!preg_match('/^\d{6,30}$/', $account)) {
            throw new InvalidArgumentException('ACB account number is not configured');
        }

        $lockName = 'payment_sync:' . self::PROVIDER . ':' . hash('sha256', $account);
        $lock = $this->db->fetch('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            return ['status' => 'locked', 'observed' => 0, 'processed' => 0, 'review' => 0];
        }

        try {
            return $this->synchronize($account);
        } catch (Throwable $error) {
            $this->recordFailure($account, $error::class);
            throw $error;
        } finally {
            $this->db->fetch('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function synchronize(string $account): array
    {
        $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
        $now = new DateTimeImmutable('now', $zone);
        $state = $this->db->fetch(
            'SELECT * FROM payment_sync_states WHERE provider=? AND source_account=?',
            [self::PROVIDER, $account]
        );
        $from = $this->windowStart($state, $now, $zone);
        $to = $now->setTime(23, 59, 59);
        $pageSize = max(1, min(500, (int) ($this->config['page_size'] ?? 100)));
        $maxPages = max(1, min(100, (int) ($this->config['max_pages'] ?? 20)));

        $rawTransactions = [];
        $complete = false;
        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $this->client->transactionPage($account, $from, $to, $page, $pageSize);
            $transactions = $result['transactions'];
            foreach ($transactions as $transaction) {
                if (is_array($transaction)) {
                    $rawTransactions[] = $transaction;
                }
            }
            if (count($transactions) < $pageSize) {
                $complete = true;
                break;
            }
            $total = $result['total'] ?? null;
            if (is_numeric($total) && $page * $pageSize >= (int) $total) {
                $complete = true;
                break;
            }
        }
        if (!$complete) {
            throw new RuntimeException('ACB_HISTORY_INCOMPLETE');
        }

        $observed = 0;
        $review = 0;
        foreach ($rawTransactions as $raw) {
            $normalized = $this->normalize($raw, $account, $zone);
            if ($normalized === null) {
                $review++;
                continue;
            }
            $source = $this->storeSource($normalized, $raw);
            if (in_array($source['processing_status'], ['observed', 'failed'], true)) {
                $observed++;
            }
        }

        $processing = $this->processStoredSources($account, $zone);
        $processed = $processing['processed'];
        $review += $processing['review'];

        $this->db->execute(
            'INSERT INTO payment_sync_states
             (provider,source_account,last_success_at,last_window_from,last_window_to,last_error_code,consecutive_failures)
             VALUES(?,?,NOW(),?,?,NULL,0)
             ON DUPLICATE KEY UPDATE last_success_at=NOW(),last_window_from=VALUES(last_window_from),
                last_window_to=VALUES(last_window_to),last_error_code=NULL,consecutive_failures=0',
            [self::PROVIDER, $account, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]
        );

        return ['status' => 'ok', 'observed' => $observed, 'processed' => $processed, 'review' => $review];
    }

    private function normalize(array $raw, string $account, DateTimeZone $zone): ?array
    {
        $transactionId = trim((string) ($raw['transactionNumber'] ?? $raw['transactionID'] ?? ''));
        if ($transactionId === '' || strlen($transactionId) > 150) {
            $this->storeReview($account, $raw, 'missing_transaction_id');
            return null;
        }

        $amount = $this->normalizeAmount($raw['amount'] ?? null);
        if ($amount === null) {
            $this->storeReview($account, $raw, 'invalid_amount', $transactionId);
            return null;
        }

        $direction = $this->normalizeDirection($raw['type'] ?? $raw['transactionType'] ?? null);
        if ($direction === null) {
            $this->storeReview($account, $raw, 'unknown_direction', $transactionId, $amount);
            return null;
        }

        $occurredAt = $this->parseDate(
            $raw['activeDatetime'] ?? $raw['postingDate'] ?? $raw['effectiveDate'] ?? $raw['timestamp'] ?? null,
            $zone
        );
        $currency = strtoupper(trim((string) ($raw['currency'] ?? 'VND')));
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $currency)) {
            $currency = 'UNKNOWN';
        }

        return [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'direction' => $direction,
            'currency' => $currency,
            'reference' => trim((string) ($raw['description'] ?? '')),
            'occurred_at' => $occurredAt,
        ];
    }

    private function storeSource(array $transaction, array $raw): array
    {
        try {
            $this->db->execute(
                'INSERT INTO payment_source_transactions
                 (provider,source_account,provider_transaction_id,direction,amount,currency,reference_text,occurred_at,raw_payload)
                 VALUES(?,?,?,?,?,?,?,?,?)',
                [
                    self::PROVIDER,
                    $this->config['account_number'],
                    $transaction['transaction_id'],
                    $transaction['direction'],
                    $transaction['amount'],
                    $transaction['currency'],
                    $transaction['reference'],
                    $transaction['occurred_at']?->format('Y-m-d H:i:s'),
                    $this->encode($raw),
                ]
            );
            return ['id' => $this->db->lastInsertId(), 'processing_status' => 'observed'];
        } catch (PDOException $error) {
            if (!((string) $error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062)) {
                throw $error;
            }
        }

        $existing = $this->db->fetch(
            'SELECT id,processing_status FROM payment_source_transactions
             WHERE provider=? AND source_account=? AND provider_transaction_id=?',
            [self::PROVIDER, $this->config['account_number'], $transaction['transaction_id']]
        );
        if ($existing === null) {
            throw new RuntimeException('Duplicate ACB transaction was not found');
        }
        return $existing;
    }

    private function storeReview(
        string $account,
        array $raw,
        string $reason,
        ?string $transactionId = null,
        string $amount = '0.00000000',
    ): void {
        $transactionId ??= 'review:' . hash('sha256', $this->encode($raw));
        try {
            $this->db->execute(
                "INSERT INTO payment_source_transactions
                 (provider,source_account,provider_transaction_id,direction,amount,currency,raw_payload,processing_status,result_message)
                 VALUES(?,?,?,'unknown',?,'UNKNOWN',?,'review',?)",
                [self::PROVIDER, $account, $transactionId, $amount, $this->encode($raw), $reason]
            );
        } catch (PDOException $error) {
            if (!((string) $error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062)) {
                throw $error;
            }
        }
    }

    private function mayProcess(?DateTimeImmutable $occurredAt, DateTimeZone $zone): bool
    {
        if (empty($this->config['processing_enabled'])) {
            return false;
        }
        $activation = trim((string) ($this->config['activation_time'] ?? ''));
        if ($activation === '' || $occurredAt === null) {
            return false;
        }
        try {
            return $occurredAt >= new DateTimeImmutable($activation, $zone);
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid ACB activation time');
        }
    }

    private function processStoredSources(string $account, DateTimeZone $zone): array
    {
        $limit = max(1, min(1000, (int) ($this->config['processing_batch_size'] ?? 200)));
        $sources = $this->db->fetchAll(
            "SELECT * FROM payment_source_transactions
             WHERE provider=? AND source_account=? AND processing_status IN ('observed','failed')
             ORDER BY COALESCE(occurred_at, discovered_at),id LIMIT " . $limit,
            [self::PROVIDER, $account]
        );
        $processed = 0;
        $review = 0;

        foreach ($sources as $source) {
            $sourceId = (int) $source['id'];
            if ($source['direction'] !== 'incoming') {
                $this->markSource($sourceId, 'ignored', 'not_incoming');
                continue;
            }
            $occurredAt = $this->parseDate($source['occurred_at'] ?? null, $zone);
            if (!$this->mayProcess($occurredAt, $zone)) {
                $this->markSource($sourceId, 'observed', 'processing_disabled_or_before_activation');
                continue;
            }

            try {
                $payload = json_decode((string) $source['raw_payload'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new UnexpectedValueException('Stored ACB payload is invalid');
                }
                $event = new PaymentEvent(
                    provider: self::PROVIDER,
                    providerEventId: (string) $source['provider_transaction_id'],
                    destinationAccount: $account,
                    amount: (string) $source['amount'],
                    currency: (string) $source['currency'],
                    reference: (string) $source['reference_text'],
                    direction: (string) $source['direction'],
                    occurredAt: $occurredAt,
                    payload: $payload,
                );
                $this->processor->process($event);
                $paymentEvent = $this->db->fetch(
                    'SELECT id,result_type FROM payment_events
                     WHERE provider=? AND destination_account=? AND provider_event_id=?',
                    [self::PROVIDER, $account, $source['provider_transaction_id']]
                );
                if ($paymentEvent === null) {
                    throw new RuntimeException('Payment event was not persisted');
                }

                $result = (string) $paymentEvent['result_type'];
                $status = in_array($result, ['unmatched', 'ambiguous_reference', 'unsupported_currency'], true)
                    ? 'review'
                    : 'processed';
                $this->db->execute(
                    'UPDATE payment_source_transactions
                     SET processing_status=?,payment_event_id=?,result_message=?,processed_at=NOW()
                     WHERE id=?',
                    [$status, $paymentEvent['id'], $result, $sourceId]
                );
                if ($status === 'review') {
                    $review++;
                } else {
                    $processed++;
                }
            } catch (Throwable $error) {
                $this->markSource($sourceId, 'failed', $error::class);
                throw $error;
            }
        }

        return ['processed' => $processed, 'review' => $review];
    }

    private function windowStart(?array $state, DateTimeImmutable $now, DateTimeZone $zone): DateTimeImmutable
    {
        if (!empty($state['last_success_at'])) {
            $last = new DateTimeImmutable((string) $state['last_success_at'], $zone);
            $minutes = max(5, min(10080, (int) ($this->config['lookback_minutes'] ?? 180)));
            return $last->modify('-' . $minutes . ' minutes');
        }
        $days = max(1, min(31, (int) ($this->config['initial_lookback_days'] ?? 2)));
        return $now->modify('-' . $days . ' days')->setTime(0, 0, 0);
    }

    private function normalizeDirection(mixed $value): ?string
    {
        return match (strtoupper(trim((string) $value))) {
            'IN', 'CREDIT', 'CR', 'C' => 'incoming',
            'OUT', 'DEBIT', 'DR', 'D' => 'outgoing',
            default => null,
        };
    }

    private function normalizeAmount(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? $value . '.00000000' : null;
        }
        if (is_float($value)) {
            $value = number_format($value, 8, '.', '');
        }
        $text = str_replace([',', ' '], '', trim((string) $value));
        if (!preg_match('/^(\d+)(?:\.(\d{1,8}))?$/', $text, $matches)) {
            return null;
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches[2] ?? '', 8, '0');
        $normalized = $whole . '.' . $fraction;
        return $normalized === '0.00000000' ? null : $normalized;
    }

    private function parseDate(mixed $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (is_numeric($value) && (float) $value > 0) {
            $timestamp = (float) $value > 100000000000 ? (int) ((float) $value / 1000) : (int) $value;
            return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($text, $zone);
        } catch (Throwable) {
            return null;
        }
    }

    private function markSource(int $sourceId, string $status, string $message): void
    {
        $this->db->execute(
            'UPDATE payment_source_transactions SET processing_status=?,result_message=? WHERE id=?',
            [$status, substr($message, 0, 190), $sourceId]
        );
    }

    private function recordFailure(string $account, string $errorCode): void
    {
        $this->db->execute(
            'INSERT INTO payment_sync_states(provider,source_account,last_error_code,consecutive_failures)
             VALUES(?,?,?,1)
             ON DUPLICATE KEY UPDATE last_error_code=VALUES(last_error_code),consecutive_failures=consecutive_failures+1',
            [self::PROVIDER, $account, substr($errorCode, 0, 100)]
        );
    }

    private function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}
