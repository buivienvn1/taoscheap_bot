<?php

declare(strict_types=1);

final class WarrantyService
{
    public function __construct(
        private Database $db,
        private SupplierRegistry $registry,
        private NotificationOutbox $outbox
    ) {
    }

    /** @return array{success: bool, order_id?: int, order_code?: string, reason?: string, pending?: bool} */
    public function create(
        int $parentOrderId,
        int $quantity,
        string $sourceType,
        string $manualContent,
        ?int $supplierSourceId,
        string $requestToken
    ): array {
        $sourceType = in_array($sourceType, ['manual', 'stock', 'api'], true) ? $sourceType : '';
        $requestToken = trim($requestToken);
        if ($quantity < 1 || $sourceType === '' || $requestToken === '' || strlen($requestToken) > 64) {
            return ['success' => false, 'reason' => 'invalid_request'];
        }

        $manualItems = $sourceType === 'manual' ? $this->manualItems($manualContent) : [];
        if ($sourceType === 'manual' && count($manualItems) !== $quantity) {
            return ['success' => false, 'reason' => 'manual_quantity_mismatch'];
        }

        $existing = $this->warrantyByToken($requestToken);
        if ($existing !== null) return $this->resumeOrReturn($existing);

        try {
            $claim = $this->db->transaction(function (Database $db) use (
                $parentOrderId,
                $quantity,
                $sourceType,
                $supplierSourceId,
                $requestToken,
                $manualItems
            ): array {
                $duplicate = $db->fetch(
                    "SELECT * FROM orders WHERE request_token=? AND order_type='warranty' FOR UPDATE",
                    [$requestToken]
                );
                if ($duplicate !== null) return ['existing' => $duplicate];

                $parent = $db->fetch(
                    "SELECT o.*,u.telegram_id,u.language
                     FROM orders o JOIN users u ON u.id=o.user_id
                     WHERE o.id=? FOR UPDATE",
                    [$parentOrderId]
                );
                if ($parent === null
                    || $parent['status'] !== 'completed'
                    || $parent['result_reason'] !== 'delivered') {
                    return ['error' => 'order_not_eligible'];
                }

                $source = null;
                if ($sourceType === 'api') {
                    $source = $db->fetch(
                        "SELECT * FROM supplier_sources
                         WHERE id=? AND variant_id=? AND source_type='api' AND status=1 FOR UPDATE",
                        [$supplierSourceId, $parent['variant_id']]
                    );
                    if ($source === null) return ['error' => 'api_source_invalid'];
                    $provider = $this->registry->get((string) $source['provider_key']);
                    if ($provider === null
                        || !$provider->isConfigured()
                        || trim((string) $source['remote_product_code']) === '') {
                        return ['error' => 'api_source_unavailable'];
                    }
                }

                $orderCode = Helpers::orderCode();
                $isApi = $sourceType === 'api';
                $completedAt = $isApi ? null : date('Y-m-d H:i:s');
                $db->execute(
                    "INSERT INTO orders
                     (order_code,order_type,parent_order_id,warranty_source,warranty_supplier_source_id,
                      user_id,variant_id,quantity,unit_price,discount_amount,product_name,request_token,
                      amount,wallet_amount,payment_method,status,result_reason,paid_at,completed_at)
                     VALUES(?,'warranty',?,?,?,?,?,?,0,0,?,?,0,0,'wallet',?,?,NOW(),?)",
                    [
                        $orderCode,
                        $parentOrderId,
                        $sourceType,
                        $source['id'] ?? null,
                        $parent['user_id'],
                        $parent['variant_id'],
                        $quantity,
                        $parent['product_name'],
                        $requestToken,
                        $isApi ? 'processing' : 'completed',
                        $isApi ? 'warranty_processing' : 'delivered',
                        $completedAt,
                    ]
                );
                $orderId = $db->lastInsertId();
                $items = $manualItems;

                if ($sourceType === 'manual') {
                    foreach ($items as $item) {
                        $db->execute('INSERT INTO order_deliveries(order_id,content) VALUES(?,?)', [$orderId, $item]);
                    }
                } elseif ($sourceType === 'stock') {
                    $stocks = $db->fetchAll(
                        'SELECT id,content FROM stocks WHERE variant_id=? AND is_sold=0 ORDER BY id LIMIT '
                        . $quantity . ' FOR UPDATE',
                        [$parent['variant_id']]
                    );
                    if (count($stocks) !== $quantity) throw new WarrantyException('out_of_stock');
                    $items = [];
                    foreach ($stocks as $stock) {
                        if ($db->execute(
                            'UPDATE stocks SET is_sold=1,order_id=?,sold_at=NOW() WHERE id=? AND is_sold=0',
                            [$orderId, $stock['id']]
                        ) !== 1) {
                            throw new WarrantyException('stock_conflict');
                        }
                        $db->execute(
                            'INSERT INTO order_deliveries(order_id,stock_id,content) VALUES(?,?,?)',
                            [$orderId, $stock['id'], $stock['content']]
                        );
                        $items[] = (string) $stock['content'];
                    }
                }

                return compact('orderId', 'orderCode', 'parent', 'source', 'items', 'quantity');
            });
        } catch (WarrantyException $error) {
            return ['success' => false, 'reason' => $error->getMessage()];
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23000') throw $error;
            $existing = $this->warrantyByToken($requestToken);
            if ($existing === null) throw $error;
            return $this->resumeOrReturn($existing);
        }

        if (isset($claim['error'])) return ['success' => false, 'reason' => $claim['error']];
        if (isset($claim['existing'])) return $this->resumeOrReturn($claim['existing']);

        $context = [
            'order_id' => $claim['orderId'],
            'order_code' => $claim['orderCode'],
            'parent' => $claim['parent'],
            'source' => $claim['source'],
            'items' => $claim['items'],
            'quantity' => $claim['quantity'],
        ];
        if ($sourceType === 'api') return $this->fulfillApi($context);

        $this->completeNotification($context, $context['items']);
        return ['success' => true, 'order_id' => $context['order_id'], 'order_code' => $context['order_code']];
    }

    /** @return list<array{id: int, label: string, provider_key: string, priority: int}> */
    public function apiSources(int $variantId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id,provider_key,remote_product_code,priority
             FROM supplier_sources
             WHERE variant_id=? AND source_type='api' AND status=1 ORDER BY priority,id",
            [$variantId]
        );
        $result = [];
        foreach ($rows as $row) {
            $provider = $this->registry->get((string) $row['provider_key']);
            if ($provider === null || !$provider->isConfigured()) continue;
            $result[] = [
                'id' => (int) $row['id'],
                'label' => $provider->name() . ' · ' . (string) $row['remote_product_code'],
                'provider_key' => (string) $row['provider_key'],
                'priority' => (int) $row['priority'],
            ];
        }
        return $result;
    }

    private function fulfillApi(array $context): array
    {
        $source = $context['source'];
        $provider = $source === null ? null : $this->registry->get((string) $source['provider_key']);
        if ($provider === null || !$provider->isConfigured()) {
            return $this->failApi($context, 'api_source_unavailable');
        }

        try {
            $result = $provider->deliver(
                (string) $source['remote_product_code'],
                (int) $context['quantity'],
                'warranty-' . $context['order_id'] . '-' . $source['id']
            );
        } catch (Throwable $error) {
            $result = [
                'success' => false,
                'retryable' => true,
                'error_code' => 'supplier_transport_error',
                'error' => $error::class,
            ];
        }

        $items = array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), (array) ($result['items'] ?? [])),
            static fn (string $item): bool => $item !== ''
        ));
        if (!($result['success'] ?? false) || count($items) !== (int) $context['quantity']) {
            $retryable = (bool) ($result['retryable'] ?? false)
                || (($result['success'] ?? false) && count($items) !== (int) $context['quantity']);
            $reason = (string) ($result['error_code'] ?? ($retryable ? 'supplier_pending' : 'supplier_failed'));
            $this->db->execute(
                'UPDATE orders SET status=?,result_reason=?,completed_at=? WHERE id=?',
                [$retryable ? 'processing' : 'failed', $reason, $retryable ? null : date('Y-m-d H:i:s'), $context['order_id']]
            );
            return [
                'success' => false,
                'pending' => $retryable,
                'order_id' => $context['order_id'],
                'order_code' => $context['order_code'],
                'reason' => $reason,
            ];
        }

        $created = $this->db->transaction(function (Database $db) use ($context, $source, $result, $items): bool {
            $order = $db->fetch('SELECT status FROM orders WHERE id=? FOR UPDATE', [$context['order_id']]);
            if ($order === null || $order['status'] === 'completed') return false;
            foreach ($items as $item) {
                $db->execute(
                    'INSERT INTO order_deliveries(order_id,supplier_source_id,provider_order_id,content) VALUES(?,?,?,?)',
                    [$context['order_id'], $source['id'], $result['provider_order_id'] ?? null, $item]
                );
            }
            $db->execute(
                "UPDATE orders SET stock_content=?,status='completed',result_reason='delivered',completed_at=NOW() WHERE id=?",
                [implode("\n", $items), $context['order_id']]
            );
            return true;
        });
        if ($created) $this->completeNotification($context, $items);
        return ['success' => true, 'order_id' => $context['order_id'], 'order_code' => $context['order_code']];
    }

    private function apiContext(array $order): array
    {
        $parent = $this->db->fetch(
            'SELECT o.*,u.telegram_id,u.language FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?',
            [$order['parent_order_id']]
        );
        $source = $this->db->fetch('SELECT * FROM supplier_sources WHERE id=?', [$order['warranty_supplier_source_id']]);
        if ($parent === null || $source === null) throw new WarrantyException('api_source_unavailable');
        return [
            'order_id' => (int) $order['id'],
            'order_code' => (string) $order['order_code'],
            'parent' => $parent,
            'source' => $source,
            'items' => [],
            'quantity' => (int) $order['quantity'],
        ];
    }

    private function failApi(array $context, string $reason): array
    {
        $this->db->execute(
            "UPDATE orders SET status='failed',result_reason=?,completed_at=NOW() WHERE id=?",
            [$reason, $context['order_id']]
        );
        return ['success' => false, 'order_id' => $context['order_id'], 'reason' => $reason];
    }

    private function completeNotification(array $context, array $items): void
    {
        $this->db->execute('UPDATE orders SET stock_content=? WHERE id=?', [implode("\n", $items), $context['order_id']]);
        $outboxId = $this->outbox->enqueue(
            (int) $context['parent']['user_id'],
            'warranty:' . $context['order_id'] . ':user',
            'warranty_delivery_success',
            [
                'order' => (string) $context['order_code'],
                'parent_order' => (string) $context['parent']['order_code'],
                'product' => (string) ($context['parent']['product_name'] ?: '-'),
                'quantity' => count($items),
                'deliveries' => $items,
            ]
        );
        $this->outbox->deliver($outboxId);
    }

    /** @return list<string> */
    private function manualItems(string $content): array
    {
        $items = [];
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') $items[] = $line;
        }
        return $items;
    }

    private function warrantyByToken(string $requestToken): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM orders WHERE request_token=? AND order_type='warranty'",
            [$requestToken]
        );
    }

    private function resumeOrReturn(array $order): array
    {
        if ($order['status'] !== 'processing' || $order['warranty_source'] !== 'api') {
            return $this->existingResult($order);
        }

        try {
            return $this->fulfillApi($this->apiContext($order));
        } catch (WarrantyException $error) {
            return [
                'success' => false,
                'pending' => false,
                'order_id' => (int) $order['id'],
                'order_code' => (string) $order['order_code'],
                'reason' => $error->getMessage(),
            ];
        }
    }

    private function existingResult(array $order): array
    {
        return [
            'success' => $order['status'] === 'completed',
            'pending' => $order['status'] === 'processing',
            'order_id' => (int) $order['id'],
            'order_code' => (string) $order['order_code'],
            'reason' => (string) ($order['result_reason'] ?? ''),
        ];
    }
}

final class WarrantyException extends RuntimeException
{
}
