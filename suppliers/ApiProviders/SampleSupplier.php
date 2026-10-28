<?php

declare(strict_types=1);

final class SampleSupplier extends BaseApiSupplier
{
    public function getStock(int $variantId): int
    {
        throw new LogicException('Cần API docs nhà cung cấp để triển khai getStock().');
    }

    public function deliver(int $variantId, array $orderContext): array
    {
        throw new LogicException('Cần API docs nhà cung cấp để triển khai deliver().');
    }
}
