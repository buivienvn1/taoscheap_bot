<?php

declare(strict_types=1);

final class PaymentFeedService
{
    public function __construct(private Database $db, private PaymentProcessor $processor, private array $config)
    {
    }

    public function run(array $feed): array
    {
        $provider = substr((string) ($this->config['provider'] ?? 'acb_third_party'), 0, 30);
        $processing = (bool) ($this->config['processing'] ?? false);
        $this->db->execute(
            "INSERT INTO payment_feed_runs(provider,mode,status,stale,incomplete,source_last_sync_at,source_last_sync_ok_at) VALUES(?,?, 'running',?,?,?,?)",
            [$provider, $processing ? 'processing' : 'read_only', $feed['stale'] ? 1 : 0, $feed['incomplete'] ? 1 : 0, $feed['last_sync_at'], $feed['last_sync_ok_at']]
        );
        $runId = $this->db->lastInsertId();
        $matched = $processed = $review = 0;
        try {
            foreach ($feed['transactions'] as $payment) {
                if ($payment['direction'] !== 'IN') continue;
                $codes = PaymentCodeRegistry::extractCodes($payment['content']);
                $code = count($codes) === 1 ? $codes[0] : null;
                if ($code !== null) $matched++;
                $fingerprint = hash('sha256', implode('|', [
                    $payment['provider_transaction_id'], $payment['provider_transaction_number'], $payment['amount'],
                    $payment['transaction_at'], hash('sha256', $payment['content']),
                ]));
                $resultType = !$feed['safe'] ? 'unsafe_feed' : (!$processing ? 'read_only' : 'observed');
                if ($feed['safe'] && $processing && $this->afterActivation($payment['transaction_at'])) {
                    $result = $this->processor->process($payment);
                    $resultType = $result['result'];
                    if ($result['outbox_id'] !== null) $processed++;
                    if (in_array($resultType, ['review', 'multiple_codes'], true)) $review++;
                }
                $this->db->execute(
                    "INSERT IGNORE INTO payment_feed_observations(run_id,provider,fingerprint,provider_transaction_id_hash,provider_transaction_number_hash,payment_code,amount,direction,transaction_at,result_type) VALUES(?,?,?,?,?,?,?,?,?,?)",
                    [$runId, $provider, $fingerprint, self::nullableHash($payment['provider_transaction_id']), self::nullableHash($payment['provider_transaction_number']), $code, $payment['amount'], 'IN', substr($payment['transaction_at'], 0, 50), $resultType]
                );
            }
            $status = $feed['safe'] ? 'ok' : 'unsafe';
            $this->db->execute('UPDATE payment_feed_runs SET status=?,transaction_count=?,matched_count=?,processed_count=?,review_count=?,finished_at=NOW() WHERE id=?', [$status, count($feed['transactions']), $matched, $processed, $review, $runId]);
            return compact('runId', 'status', 'matched', 'processed', 'review');
        } catch (Throwable $error) {
            $this->db->execute("UPDATE payment_feed_runs SET status='failed',safe_error_code=?,finished_at=NOW() WHERE id=?", [substr($error::class, 0, 50), $runId]);
            throw $error;
        }
    }

    private function afterActivation(string $date): bool
    {
        $activation = trim((string) ($this->config['activation_at'] ?? ''));
        if ($activation === '') return false;
        try {
            return new DateTimeImmutable($date) >= new DateTimeImmutable($activation);
        } catch (Throwable) {
            return false;
        }
    }

    private static function nullableHash(string $value): ?string
    {
        return $value === '' ? null : hash('sha256', $value);
    }
}
