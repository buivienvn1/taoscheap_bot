<?php

declare(strict_types=1);

final class StockFulfillmentService
{
    public function __construct(
        private Database $db,
        private SupplierRegistry $registry
    ) {
    }

    /**
     * Điều phối giao hàng theo từng nguồn ưu tiên.
     *
     * Mỗi đoạn giữ khóa chỉ thao tác DB. Lời gọi API luôn nằm giữa hai transaction
     * (claim và persist), tuyệt đối không chạy khi transaction đang mở.
     *
     * @return array{success: bool, pending?: bool, source?: string, items?: list<string>, reason?: string}
     */
    public function fulfill(int $orderId, int $variantId, int $quantity, string $orderCode): array
    {
        $quantity = max(1, $quantity);
        $existing = $this->deliveries($orderId);
        if (count($existing) === $quantity) {
            return ['success' => true, 'source' => 'existing', 'items' => $existing];
        }
        if ($existing !== []) {
            return ['success' => false, 'pending' => true, 'reason' => 'delivery_incomplete'];
        }

        $sources = $this->db->fetchAll(
            'SELECT * FROM supplier_sources WHERE variant_id=? AND status=1 ORDER BY priority,id',
            [$variantId]
        );
        foreach ($sources as $source) {
            if ($source['source_type'] === 'manual') {
                $result = $this->fulfillManual($orderId, $variantId, (int) $source['id'], $quantity);
            } else {
                $result = $this->fulfillRemote($orderId, $source, $quantity, $orderCode);
            }
            if ($result['success'] || ($result['pending'] ?? false)) {
                return $result;
            }
        }
        return ['success' => false, 'reason' => 'out_of_stock'];
    }

    /** @return array{success: bool, source?: string, items?: list<string>, reason?: string} */
    private function fulfillManual(int $orderId, int $variantId, int $sourceId, int $quantity): array
    {
        return $this->db->transaction(function (Database $db) use ($orderId, $variantId, $sourceId, $quantity): array {
            $existing = $this->deliveries($orderId);
            if (count($existing) === $quantity) {
                return ['success' => true, 'source' => 'existing', 'items' => $existing];
            }
            if ($existing !== []) {
                throw new RuntimeException('delivery_incomplete');
            }

            $stocks = $db->fetchAll(
                'SELECT id,content FROM stocks WHERE variant_id=? AND is_sold=0 ORDER BY id LIMIT ' . $quantity . ' FOR UPDATE',
                [$variantId]
            );
            if (count($stocks) !== $quantity) {
                return ['success' => false, 'reason' => 'out_of_stock'];
            }

            $items = [];
            foreach ($stocks as $stock) {
                if ($db->execute(
                    'UPDATE stocks SET is_sold=1,order_id=?,sold_at=NOW() WHERE id=? AND is_sold=0',
                    [$orderId, $stock['id']]
                ) !== 1) {
                    throw new RuntimeException('manual_stock_allocation_conflict');
                }
                $db->execute(
                    'INSERT INTO order_deliveries(order_id,stock_id,supplier_source_id,content) VALUES(?,?,?,?)',
                    [$orderId, $stock['id'], $sourceId, $stock['content']]
                );
                $items[] = (string) $stock['content'];
            }
            return ['success' => true, 'source' => 'manual', 'items' => $items];
        });
    }

    /** @return array{success: bool, pending?: bool, source?: string, items?: list<string>, reason?: string} */
    private function fulfillRemote(int $orderId, array $source, int $quantity, string $orderCode): array
    {
        $providerKey = (string) ($source['provider_key'] ?? '');
        $remoteCode = trim((string) ($source['remote_product_code'] ?? ''));
        $provider = $this->registry->get($providerKey);
        if ($provider === null || $remoteCode === '') {
            return ['success' => false, 'reason' => 'provider_not_configured'];
        }

        $claim = $this->claimRemoteAttempt($orderId, (int) $source['id'], $orderCode, $quantity);
        if ($claim['result'] !== 'call') {
            return $claim['response'];
        }

        // Cố ý ở ngoài transaction: network timeout không kéo dài bất kỳ row lock nào.
        try {
            $remoteResult = $provider->deliver($remoteCode, $quantity, $claim['idempotency_key']);
        } catch (Throwable $error) {
            $remoteResult = [
                'success' => false,
                'retryable' => true,
                'error_code' => 'supplier_transport_error',
                'error' => $error::class,
            ];
        }

        return $this->persistRemoteResult(
            $orderId,
            (int) $source['id'],
            $providerKey,
            $quantity,
            $remoteResult
        );
    }

    /** @return array{result: string, idempotency_key?: string, response: array} */
    private function claimRemoteAttempt(int $orderId, int $sourceId, string $orderCode, int $quantity): array
    {
        return $this->db->transaction(function (Database $db) use ($orderId, $sourceId, $orderCode, $quantity): array {
            $attempt = $db->fetch(
                'SELECT * FROM supplier_fulfillment_attempts WHERE order_id=? AND supplier_source_id=? FOR UPDATE',
                [$orderId, $sourceId]
            );
            if ($attempt !== null) {
                if ($attempt['status'] === 'completed') {
                    $items = $this->deliveries($orderId, $sourceId);
                    return ['result' => 'completed', 'response' => [
                        'success' => count($items) === $quantity,
                        'items' => $items,
                        'source' => 'existing',
                    ]];
                }
                if ($attempt['status'] === 'failed') {
                    return ['result' => 'failed', 'response' => [
                        'success' => false,
                        'reason' => (string) ($attempt['error_code'] ?: 'supplier_failed'),
                    ]];
                }

                $updatedAt = strtotime((string) $attempt['updated_at']) ?: 0;
                $processingIsStale = $attempt['status'] === 'processing' && $updatedAt <= time() - 90;
                if ($attempt['status'] === 'processing' && !$processingIsStale) {
                    return ['result' => 'pending', 'response' => [
                        'success' => false,
                        'pending' => true,
                        'reason' => 'supplier_pending',
                    ]];
                }

                // Retry unknown hoặc processing quá hạn bằng đúng idempotency key cũ.
                // Khóa hàng đảm bảo tại một thời điểm chỉ một worker được quyền gọi lại API.
                $db->execute(
                    "UPDATE supplier_fulfillment_attempts SET status='processing',error_code=NULL,error_message=NULL WHERE id=?",
                    [$attempt['id']]
                );
                return [
                    'result' => 'call',
                    'idempotency_key' => (string) $attempt['idempotency_key'],
                    'response' => ['success' => false, 'pending' => true, 'reason' => 'supplier_pending'],
                ];
            }

            $idempotencyKey = 'taos-' . $orderId . '-' . $sourceId . '-' . substr(hash('sha256', $orderCode), 0, 16);
            $db->execute(
                "INSERT INTO supplier_fulfillment_attempts(order_id,supplier_source_id,idempotency_key,status) VALUES(?,?,?,'processing')",
                [$orderId, $sourceId, $idempotencyKey]
            );
            return [
                'result' => 'call',
                'idempotency_key' => $idempotencyKey,
                'response' => ['success' => false, 'pending' => true, 'reason' => 'supplier_pending'],
            ];
        });
    }

    /** @return array{success: bool, pending?: bool, source?: string, items?: list<string>, reason?: string} */
    private function persistRemoteResult(int $orderId, int $sourceId, string $providerKey, int $quantity, array $result): array
    {
        return $this->db->transaction(function (Database $db) use ($orderId, $sourceId, $providerKey, $quantity, $result): array {
            $attempt = $db->fetch(
                'SELECT * FROM supplier_fulfillment_attempts WHERE order_id=? AND supplier_source_id=? FOR UPDATE',
                [$orderId, $sourceId]
            );
            if ($attempt === null) {
                throw new RuntimeException('supplier_attempt_missing');
            }
            if ($attempt['status'] === 'completed') {
                $items = $this->deliveries($orderId, $sourceId);
                return ['success' => count($items) === $quantity, 'source' => $providerKey, 'items' => $items];
            }

            $raw = json_encode($result['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($result['success'] ?? false) {
                $items = array_values(array_map('strval', (array) ($result['items'] ?? [])));
                if (count($items) !== $quantity) {
                    $result = $result + ['error_code' => 'remote_delivery_quantity_mismatch'];
                    $result['success'] = false;
                    $result['retryable'] = true;
                } else {
                    foreach ($items as $item) {
                        $db->execute(
                            'INSERT INTO order_deliveries(order_id,supplier_source_id,provider_order_id,content) VALUES(?,?,?,?)',
                            [$orderId, $sourceId, $result['provider_order_id'] ?? null, $item]
                        );
                    }
                    $db->execute(
                        "UPDATE supplier_fulfillment_attempts SET provider_order_id=?,status='completed',response_payload=?,completed_at=NOW() WHERE id=?",
                        [$result['provider_order_id'] ?? null, $raw ?: null, $attempt['id']]
                    );
                    return ['success' => true, 'source' => $providerKey, 'items' => $items];
                }
            }

            $retryable = (bool) ($result['retryable'] ?? false);
            $db->execute(
                'UPDATE supplier_fulfillment_attempts SET status=?,error_code=?,error_message=?,response_payload=? WHERE id=?',
                [
                    $retryable ? 'unknown' : 'failed',
                    substr((string) ($result['error_code'] ?? 'supplier_error'), 0, 100),
                    substr((string) ($result['error'] ?? 'Supplier fulfillment failed.'), 0, 255),
                    $raw ?: null,
                    $attempt['id'],
                ]
            );
            return $retryable
                ? ['success' => false, 'pending' => true, 'reason' => 'supplier_pending']
                : ['success' => false, 'reason' => (string) ($result['error_code'] ?? 'supplier_failed')];
        });
    }

    /** @return list<string> */
    private function deliveries(int $orderId, ?int $sourceId = null): array
    {
        $sql = 'SELECT content FROM order_deliveries WHERE order_id=?';
        $params = [$orderId];
        if ($sourceId !== null) {
            $sql .= ' AND supplier_source_id=?';
            $params[] = $sourceId;
        }
        $rows = $this->db->fetchAll($sql . ' ORDER BY id', $params);
        return array_map(static fn (array $row): string => (string) $row['content'], $rows);
    }
}
