<?php

declare(strict_types=1);

final class ManualStock
{
    public function __construct(private Database $db)
    {
    }

    public function getStock(int $variantId): int
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS total FROM stocks WHERE variant_id = ? AND is_sold = 0',
            [$variantId]
        );
        return (int) ($row['total'] ?? 0);
    }
}
