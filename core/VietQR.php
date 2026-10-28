<?php

declare(strict_types=1);

final class VietQR
{
    public function __construct(private array $bank)
    {
    }

    public function imageUrl(string $content, ?float $amount = null): string
    {
        $bankId = strtoupper((string) $this->bank['bank_id']);
        if ($bankId === 'ACB') {
            $bankId = '970416';
        }

        $base = sprintf(
            'https://img.vietqr.io/image/%s-%s-compact.jpg',
            rawurlencode($bankId),
            rawurlencode($this->bank['account_number'])
        );
        $query = ['addInfo' => $content];

        if ($amount !== null && $amount > 0) {
            $query['amount'] = (string) (int) $amount;
        }

        return $base . '?' . http_build_query($query);
    }
    public function transferDetails(): array
    {
        return [
            'bank_id' => (string) $this->bank['bank_id'],
            'account_number' => (string) $this->bank['account_number'],
            'account_name' => (string) $this->bank['account_name'],
        ];
    }
}
