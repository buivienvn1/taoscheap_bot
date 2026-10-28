<?php

declare(strict_types=1);

final class ManualStock implements SupplierInterface
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

    public function deliver(int $variantId, array $orderContext): array
    {
        return $this->db->transaction(function (Database $db) use ($variantId, $orderContext): array {
            $stock = $db->fetch(
                'SELECT id, content FROM stocks WHERE variant_id = ? AND is_sold = 0 ORDER BY id LIMIT 1 FOR UPDATE',
                [$variantId]
            );
            if ($stock === null) {
                return ['success' => false, 'error' => 'Sản phẩm vừa hết hàng.'];
            }

            $changed = $db->execute(
                'UPDATE stocks SET is_sold = 1, order_id = ?, sold_at = NOW() WHERE id = ? AND is_sold = 0',
                [$orderContext['order_id'], $stock['id']]
            );
            if ($changed !== 1) {
                throw new RuntimeException('Không thể khóa kho hàng.');
            }

            return ['success' => true, 'content' => $stock['content']];
        });
    }
}
