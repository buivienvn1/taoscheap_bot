<?php

declare(strict_types=1);

interface SupplierInterface
{
    public function key(): string;

    public function name(): string;

    public function isConfigured(): bool;

    /** @return array<string, int|float|string> */
    public function getBalance(): array;

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     stock: int,
     *     available: bool,
     *     unit_price: string|null,
     *     currency: string|null,
     *     price_kind: string,
     *     max_quantity: int|null,
     *     raw: array<string, mixed>
     * }>
     */
    public function getProducts(): array;

    public function getStock(string $remoteProductCode): int;

    /**
     * @return array{
     *     success: bool,
     *     items?: list<string>,
     *     provider_order_id?: string,
     *     error_code?: string,
     *     error?: string,
     *     retryable?: bool,
     *     raw?: array<string, mixed>
     * }
     */
    public function deliver(
        string $remoteProductCode,
        int $quantity,
        string $idempotencyKey
    ): array;
}
