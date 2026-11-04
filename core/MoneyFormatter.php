<?php

declare(strict_types=1);

final class MoneyFormatter
{
    private ?float $rate = null;
    private array $preferences = [];

    public function __construct(private Database $db)
    {
    }

    public function forUser(float|int|string $amount, int $userId, bool $secondaryCodeWrapped = false): string
    {
        return $this->format($amount, $this->preference($userId), $secondaryCodeWrapped);
    }

    public function format(
        float|int|string $amount,
        string $preference = 'bank',
        bool $secondaryCodeWrapped = false
    ): string {
        return Helpers::formatDualPrice($amount, $this->rate(), $preference, $secondaryCodeWrapped);
    }

    public function admin(float|int|string $amount, bool $secondaryCodeWrapped = false): string
    {
        return $this->format($amount, 'bank', $secondaryCodeWrapped);
    }

    public function rate(): float
    {
        if ($this->rate !== null) return $this->rate;

        $setting = $this->db->fetch(
            "SELECT key_value FROM settings WHERE key_name='binance_pay_rate_usdt_vnd'"
        );
        $rate = (float) ($setting['key_value'] ?? 0);
        $this->rate = $rate > 0 ? $rate : 25500.0;
        return $this->rate;
    }

    public function preference(int $userId): string
    {
        if (isset($this->preferences[$userId])) return $this->preferences[$userId];

        $user = $this->db->fetch('SELECT preferred_payment_method FROM users WHERE id=?', [$userId]);
        $preference = (string) ($user['preferred_payment_method'] ?? 'bank');
        $this->preferences[$userId] = $preference === 'binance_pay' ? 'binance_pay' : 'bank';
        return $this->preferences[$userId];
    }
}
