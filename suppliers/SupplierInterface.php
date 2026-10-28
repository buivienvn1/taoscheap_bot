<?php

declare(strict_types=1);

interface SupplierInterface
{
    public function getStock(int $variantId): int;

    /** @return array{success: bool, content?: string, error?: string} */
    public function deliver(int $variantId, array $orderContext): array;
}
