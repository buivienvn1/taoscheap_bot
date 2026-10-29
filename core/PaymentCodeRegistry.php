<?php

declare(strict_types=1);

final class PaymentCodeRegistry
{
    public function __construct(private Database $db)
    {
    }

    public static function extractCodes(string $description): array
    {
        preg_match_all('/(?<![A-Z0-9])DONATE[A-HJ-NP-Z2-9]{6}(?![A-Z0-9])/i', $description, $matches);
        return array_values(array_unique(array_map('strtoupper', $matches[0] ?? [])));
    }

    public function reserve(string $requestType, ?int $requestId = null, int $maxAttempts = 20): string
    {
        if (!in_array($requestType, ['deposit', 'order'], true)) {
            throw new InvalidArgumentException('Unsupported payment request type');
        }

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $code = Helpers::randomCode('DONATE');
            try {
                $this->db->execute(
                    "INSERT INTO payment_code_registry(code,request_type,request_id,status) VALUES(?,?,?,'reserved')",
                    [$code, $requestType, $requestId]
                );
                return $code;
            } catch (PDOException $error) {
                if (!$this->isDuplicateKey($error)) {
                    throw $error;
                }
            }
        }

        throw new RuntimeException('Unable to reserve a unique payment code');
    }

    public function bind(string $code, string $requestType, int $requestId): void
    {
        $updated = $this->db->execute(
            "UPDATE payment_code_registry SET request_id=? WHERE code=? AND request_type=? AND request_id IS NULL AND status='reserved'",
            [$requestId, strtoupper($code), $requestType]
        );
        if ($updated !== 1) throw new RuntimeException('Unable to bind payment code');
    }

    public function registerExisting(string $code, string $requestType, int $requestId): void
    {
        $normalized = strtoupper(trim($code));
        try {
            $this->db->execute(
                "INSERT INTO payment_code_registry(code,request_type,request_id,status) VALUES(?,?,?,'reserved')",
                [$normalized, $requestType, $requestId]
            );
        } catch (PDOException $error) {
            if (!$this->isDuplicateKey($error)) {
                throw $error;
            }
            $existing = $this->db->fetch('SELECT * FROM payment_code_registry WHERE code=? FOR UPDATE', [$normalized]);
            if ($existing === null || $existing['request_type'] !== $requestType || (int) $existing['request_id'] !== $requestId) {
                throw new RuntimeException('Ambiguous payment code');
            }
        }
    }

    private function isDuplicateKey(PDOException $error): bool
    {
        return (string) $error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062;
    }
}
