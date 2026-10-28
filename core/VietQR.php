<?php

declare(strict_types=1);

final class VietQR
{
    public function __construct(private array $bank)
    {
    }

    public function imageUrl(string $content, ?float $amount = null): string
    {
        $base = sprintf(
            'https://img.vietqr.io/image/%s-%s-%s.png',
            rawurlencode($this->bank['bank_id']),
            rawurlencode($this->bank['account_number']),
            rawurlencode($this->bank['template'])
        );
        $query = [
            'addInfo' => $content,
            'accountName' => $this->bank['account_name'],
        ];

        if ($amount !== null && $amount > 0) {
            $query['amount'] = (string) (int) $amount;
        }

        return $base . '?' . http_build_query($query);
    }
}
