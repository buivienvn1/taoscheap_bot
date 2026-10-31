<?php

declare(strict_types=1);

final class BulkDiscountService
{
    private ?array $tiers = null;

    public function __construct(private Database $db)
    {
    }

    public function tiers(): array
    {
        if ($this->tiers !== null) {
            return $this->tiers;
        }

        $rows = $this->db->fetchAll(
            "SELECT key_name,key_value FROM settings WHERE key_name IN ('bulk_discount_enabled','bulk_discount_tiers')"
        );
        $settings = array_column($rows, 'key_value', 'key_name');
        return $this->tiers = self::parseTiers(
            (string) ($settings['bulk_discount_enabled'] ?? '0') === '1'
                ? (string) ($settings['bulk_discount_tiers'] ?? '')
                : ''
        );
    }

    public static function parseTiers(string $value): array
    {
        $tiers = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $parts = array_map('trim', explode(':', $line, 2));
            if (count($parts) !== 2 || !ctype_digit($parts[0])) {
                continue;
            }
            $quantity = (int) $parts[0];
            $percent = (float) str_replace(',', '.', $parts[1]);
            if ($quantity < 2 || $percent <= 0 || $percent >= 100) {
                continue;
            }
            $tiers[$quantity] = $percent;
        }

        ksort($tiers, SORT_NUMERIC);
        return array_map(
            static fn (int|string $quantity, float $percent): array => [
                'quantity' => (int) $quantity,
                'percent' => $percent,
            ],
            array_keys($tiers),
            array_values($tiers)
        );
    }

    public function eligibleTiers(int $stock): array
    {
        return self::eligibleTiersFor($this->tiers(), $stock);
    }

    public static function eligibleTiersFor(array $tiers, int $stock): array
    {
        return array_values(array_filter(
            $tiers,
            static fn (array $tier): bool => (int) $tier['quantity'] <= $stock
        ));
    }

    public function calculate(float|int|string $unitPrice, int $quantity): array
    {
        return self::calculateFor($this->tiers(), $unitPrice, $quantity);
    }

    public static function calculateFor(array $tiers, float|int|string $unitPrice, int $quantity): array
    {
        $unitPrice = (float) $unitPrice;
        $quantity = max(1, $quantity);
        $discountPercent = 0.0;
        foreach ($tiers as $tier) {
            if ($quantity >= $tier['quantity']) {
                $discountPercent = (float) $tier['percent'];
            }
        }

        $subtotal = round($unitPrice * $quantity, 2);
        $discountAmount = round($subtotal * $discountPercent / 100, 2);
        $total = round($subtotal - $discountAmount, 2);
        $nextTier = null;
        foreach ($tiers as $tier) {
            if ($tier['quantity'] > $quantity) {
                $nextTier = $tier;
                break;
            }
        }

        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'discount_percent' => $discountPercent,
            'discount_amount' => number_format($discountAmount, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
            'next_tier' => $nextTier,
        ];
    }

    public function formatPercent(float|int $percent): string
    {
        return rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
    }
}
