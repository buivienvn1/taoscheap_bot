<?php

declare(strict_types=1);

final class OrderHandler
{
    private NotificationOutbox $outbox;
    private ReferralService $referrals;

    public function __construct(
        private Database $db,
        private Telegram $telegram,
        private VietQR $vietQr,
        private array $app,
        private StockFulfillmentService $fulfillment,
        private SupplierCatalogService $supplierCatalog,
        ?ReferralService $referrals = null
    ) {
        $this->outbox = new NotificationOutbox($db, $telegram);
        $this->referrals = $referrals ?? new ReferralService($db, $telegram);
    }

    public function checkout(
        int $chatId,
        int $messageId,
        array $user,
        int $productId,
        int $version,
        Language $lang
    ): void {
        $selection = $this->db->fetch(
            'SELECT * FROM checkout_selections WHERE user_id = ? AND message_id = ? AND product_id = ?',
            [(int) $user['id'], $messageId, $productId]
        );
        if ($selection === null || (int) $selection['version'] !== $version) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('selection_expired'), [[
                ['text' => $lang->get('products_button'), 'callback_data' => 'products'],
            ]]);
            return;
        }
        $quantity = (int) $selection['quantity'];
        if ($quantity < 1 || $quantity > 100) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('quantity_invalid'));
            return;
        }
        $variant = $this->db->fetch(
            "SELECT v.*, p.id product_id, p.name product_name
             FROM products p JOIN variants v ON v.id = p.source_variant_id
             WHERE p.id = ? AND p.status = 1 AND v.status = 1",
            [$productId]
        );
        if ($variant === null) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('product_unavailable'));
            return;
        }
        $stock = $this->supplierCatalog->variantStock((int) $variant['id']);
        if ($stock['available'] < $quantity) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('just_out_of_stock'), [[
                ['text' => $lang->get('back'), 'callback_data' => 'product:' . $productId],
            ]]);
            return;
        }
        $unitPrice = number_format((float) $variant['price'], 2, '.', '');
        $total = number_format((float) $unitPrice * $quantity, 2, '.', '');
        $requestToken = hash('sha256', implode(':', [(int) $user['id'], $messageId, $productId, $version]));
        if ((float) $user['balance'] >= (float) $total) {
            $result = $this->purchaseWithWallet((int) $user['id'], $variant, $quantity, $unitPrice, $requestToken, $lang);
            $this->telegram->editMessage($chatId, $messageId, $result['message'], [[
                ['text' => $lang->get('continue_buying'), 'callback_data' => 'products'],
                ['text' => $lang->get('home'), 'callback_data' => 'start'],
            ]]);
            return;
        }

        $order = $this->pendingDirectOrder((int) $user['id'], $variant, $quantity, $unitPrice, $total, $requestToken);
        $paymentCode = (string) $order['payment_code'];
        $bankAmount = (string) $order['amount'];
        $walletAmount = (string) $order['wallet_amount'];
        $bank = $this->vietQr->transferDetails();
        $qrUrl = $this->vietQr->imageUrl($paymentCode, (float) $bankAmount);
        $content = '<blockquote><b>' . $lang->get('payment_title') . "</b></blockquote>\n\n"
            . $lang->get('payment_compact_item', [
                'product' => Helpers::escape($variant['product_name']),
                'quantity' => $quantity,
            ]) . "\n"
            . ((float) $walletAmount > 0 ? $lang->get('payment_wallet_deducted_line', ['amount' => Helpers::money($walletAmount)]) . "\n" : '')
            . $lang->get('payment_compact_need_pay', ['amount' => Helpers::money($bankAmount)]) . "\n"
            . $lang->get('payment_bank_line', [
                'bank' => Helpers::escape($bank['bank_id']),
                'account' => Helpers::escape($bank['account_number']),
            ]) . "\n"
            . $lang->get('payment_content_line', ['content' => Helpers::escape($paymentCode)]) . "\n\n"
            . $lang->get('payment_instruction');
        $keyboard = (float) $walletAmount > 0
            ? [[['text' => $lang->get('cancel_order_button'), 'callback_data' => 'cancel_order:' . $order['id']]]]
            : [[['text' => $lang->get('back'), 'callback_data' => 'product:' . $productId]]];
        try {
            $photoResult = $this->telegram->replaceWithPhoto($chatId, $messageId, $qrUrl, $content, $keyboard);
        } catch (Throwable $error) {
            error_log('Order QR delivery failed: ' . $error::class);
            $fallbackKeyboard = [[['text' => $lang->get('open_qr'), 'url' => $qrUrl]], ...$keyboard];
            $fallbackResult = $this->telegram->editMessage($chatId, $messageId, $content . "\n\n<i>" . $lang->get('qr_unavailable') . '</i>', $fallbackKeyboard);
            $fallbackMessageId = (int) ($fallbackResult['result']['message_id'] ?? $messageId);
            if ($fallbackMessageId > 0) {
                $this->recordPaymentMessage((int) $order['id'], (int) $user['id'], $chatId, $fallbackMessageId, 'text', $qrUrl);
            }
            return;
        }

        $newMessageId = (int) ($photoResult['result']['message_id'] ?? 0);
        if ($newMessageId > 0) {
            $this->recordPaymentMessage((int) $order['id'], (int) $user['id'], $chatId, $newMessageId, 'photo', $qrUrl);
        }
        if ($newMessageId > 0 && $newMessageId !== $messageId) {
            $this->db->execute(
                'INSERT INTO checkout_selections (user_id,message_id,product_id,quantity,version)
                 SELECT user_id,?,product_id,quantity,version
                 FROM checkout_selections
                 WHERE user_id=? AND message_id=?
                 ON DUPLICATE KEY UPDATE product_id=VALUES(product_id),quantity=VALUES(quantity),version=VALUES(version)',
                [$newMessageId, $user['id'], $messageId]
            );
        }
    }

    public function legacyBuy(int $chatId, int $messageId, int $userId, int $variantId, Language $lang): void
    {
        $product = $this->db->fetch('SELECT p.id FROM products p JOIN variants v ON v.id = p.source_variant_id WHERE v.id = ?', [$variantId]);
        if ($product === null) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('product_unavailable'));
            return;
        }
        $this->db->execute(
            'INSERT INTO checkout_selections (user_id, message_id, product_id, quantity, version) VALUES (?, ?, ?, 1, 1)
             ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), quantity = 1, version = version + 1',
            [$userId, $messageId, $product['id']]
        );
        $this->telegram->editMessage($chatId, $messageId, $lang->get('selection_expired'), [[
            ['text' => $lang->get('back'), 'callback_data' => 'product:' . $product['id']],
        ]]);
    }

    private function purchaseWithWallet(
        int $userId,
        array $variant,
        int $quantity,
        string $unitPrice,
        string $requestToken,
        Language $lang
    ): array {
        $prepared = $this->db->transaction(function (Database $db) use ($userId, $variant, $quantity, $unitPrice, $requestToken, $lang): array {
            $existing = $db->fetch('SELECT * FROM purchase_requests WHERE request_token = ? FOR UPDATE', [$requestToken]);
            if ($existing !== null) {
                if ($existing['order_id'] !== null) {
                    $order = $db->fetch('SELECT * FROM orders WHERE id=? FOR UPDATE', [$existing['order_id']]);
                    if ($order['status'] === 'completed') return ['response' => $this->walletOrderMessage($db, (int) $order['id'], $lang)];
                    if (in_array($order['status'], ['failed', 'cancelled'], true)) {
                        return ['response' => ['message' => $lang->get('just_out_of_stock')]];
                    }
                    if ($order['status'] === 'processing' && $order['payment_method'] === 'wallet') {
                        $orderUser = $db->fetch('SELECT * FROM users WHERE id=?', [$order['user_id']]);
                        return [
                            'orderId' => (int) $order['id'],
                            'orderCode' => (string) $order['order_code'],
                            'total' => number_format((float) $order['amount'], 2, '.', ''),
                            'user' => $orderUser,
                        ];
                    }
                }
                return ['response' => ['message' => $lang->get('request_processing')]];
            }
            $db->execute("INSERT INTO purchase_requests(request_token,user_id,variant_id,quantity,unit_price,status) VALUES(?,?,?,?,?,'processing')", [$requestToken, $userId, $variant['id'], $quantity, $unitPrice]);
            $user = $db->fetch('SELECT * FROM users WHERE id=? FOR UPDATE', [$userId]);
            $currentVariant = $db->fetch('SELECT v.price,v.status,p.status product_status FROM variants v JOIN products p ON p.id=v.product_id WHERE v.id=? FOR UPDATE', [$variant['id']]);
            if ($currentVariant === null || !(int) $currentVariant['status'] || !(int) $currentVariant['product_status']) {
                $db->execute("UPDATE purchase_requests SET status='failed',result_reason='product_unavailable' WHERE request_token=?", [$requestToken]);
                return ['response' => ['message' => $lang->get('product_unavailable')]];
            }
            if (number_format((float) $currentVariant['price'], 2, '.', '') !== $unitPrice) {
                $db->execute("UPDATE purchase_requests SET status='failed',result_reason='price_changed' WHERE request_token=?", [$requestToken]);
                return ['response' => ['message' => $lang->get('price_changed')]];
            }
            $total = number_format((float) $unitPrice * $quantity, 2, '.', '');
            if ((float) $user['balance'] < (float) $total) {
                $db->execute("UPDATE purchase_requests SET status='failed',result_reason='balance_changed' WHERE request_token=?", [$requestToken]);
                return ['response' => ['message' => $lang->get('balance_changed')]];
            }
            $orderCode = Helpers::orderCode();
            $db->execute("INSERT INTO orders(order_code,user_id,variant_id,quantity,unit_price,product_name,request_token,amount,payment_method,status,paid_at) VALUES(?,?,?,?,?,?,?,?, 'wallet','processing',NOW())", [$orderCode, $userId, $variant['id'], $quantity, $unitPrice, $variant['product_name'], $requestToken, $total]);
            $orderId = $db->lastInsertId();
            $balanceBefore = number_format((float) $user['balance'], 2, '.', '');
            $balanceAfter = number_format((float) $balanceBefore - (float) $total, 2, '.', '');
            $db->execute('UPDATE users SET balance=? WHERE id=?', [$balanceAfter, $userId]);
            $db->execute("INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,reference_type,reference_id,reference_code,note) VALUES(?,'purchase',?,?,?,'order',?,?,?)", [$userId, -((float) $total), $balanceBefore, $balanceAfter, $orderId, $orderCode, $orderCode]);
            $db->execute("UPDATE purchase_requests SET order_id=? WHERE request_token=?", [$orderId, $requestToken]);
            return compact('orderId', 'orderCode', 'total', 'user');
        });
        if (isset($prepared['response'])) return $prepared['response'];

        $delivery = $this->fulfillment->fulfill((int) $prepared['orderId'], (int) $variant['id'], $quantity, (string) $prepared['orderCode']);
        if ($delivery['pending'] ?? false) return ['message' => $lang->get('supplier_fulfillment_pending')];

        $referralEvidence = ($delivery['success'] ?? false) ? $this->referrals->inspectOrder($userId) : null;
        return $this->db->transaction(function (Database $db) use ($prepared, $delivery, $variant, $quantity, $requestToken, $lang, $referralEvidence): array {
            $order = $db->fetch('SELECT * FROM orders WHERE id=? FOR UPDATE', [$prepared['orderId']]);
            if ($order['status'] === 'completed') return $this->walletOrderMessage($db, (int) $order['id'], $lang);
            if ($order['status'] !== 'processing') {
                return ['message' => $lang->get($order['status'] === 'failed' ? 'just_out_of_stock' : 'request_processing')];
            }
            if (!$delivery['success']) {
                $user = $db->fetch('SELECT balance FROM users WHERE id=? FOR UPDATE', [$order['user_id']]);
                $before = number_format((float) $user['balance'], 2, '.', '');
                $after = number_format((float) $before + (float) $prepared['total'], 2, '.', '');
                $db->execute('UPDATE users SET balance=? WHERE id=?', [$after, $order['user_id']]);
                $db->execute("INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,reference_type,reference_id,reference_code,note) VALUES(?,'refund',?,?,?,'order',?,?,?)", [$order['user_id'], $prepared['total'], $before, $after, $order['id'], $order['order_code'], 'fulfillment_failed']);
                $db->execute("UPDATE orders SET status='failed',result_reason='out_of_stock',wallet_credited=?,completed_at=NOW() WHERE id=?", [$prepared['total'], $order['id']]);
                $db->execute("UPDATE purchase_requests SET status='failed',result_reason='out_of_stock' WHERE request_token=?", [$requestToken]);
                return ['message' => $lang->get('just_out_of_stock')];
            }
            $completedAt = date('Y-m-d H:i:s');
            $db->execute("UPDATE orders SET stock_content=?,status='completed',result_reason='delivered',completed_at=? WHERE id=?", [implode("\n", $delivery['items'] ?? []), $completedAt, $order['id']]);
            $this->referrals->recordOrder((int) $order['id'], $referralEvidence);
            $db->execute("UPDATE purchase_requests SET status='completed',result_reason='delivered' WHERE request_token=?", [$requestToken]);
            $this->enqueueWalletOrderAdmins((int) $order['id'], $prepared['user'], $variant, $quantity, $prepared['total'], $prepared['orderCode'], $completedAt);
            return $this->walletOrderMessage($db, (int) $order['id'], $lang);
        });
    }

    private function walletOrderMessage(Database $db, int $orderId, Language $lang): array
    {
        $order = $db->fetch('SELECT * FROM orders WHERE id=?', [$orderId]);
        $deliveries = $db->fetchAll('SELECT content FROM order_deliveries WHERE order_id=? ORDER BY id', [$orderId]);
        $message = $lang->get('order_result_success', [
            'product' => Helpers::escape((string) $order['product_name']),
            'quantity' => (int) $order['quantity'],
            'amount' => Helpers::money($order['amount']),
            'order' => Helpers::escape((string) $order['order_code']),
            'time' => Helpers::dateTime((string) $order['completed_at']),
        ]);
        foreach ($deliveries as $index => $delivery) {
            $message .= "\n\n<b>" . $lang->get('delivery_item', ['number' => $index + 1]) . ":</b>\n<code>" . Helpers::escape($delivery['content']) . '</code>';
        }
        return ['message' => $message];
    }

    private function enqueueWalletOrderAdmins(
        int $orderId,
        array $user,
        array $variant,
        int $quantity,
        string $total,
        string $orderCode,
        string $completedAt
    ): void {
        $customer = trim((string) $user['first_name'] . ' ' . (string) ($user['last_name'] ?? ''));
        if (trim((string) ($user['username'] ?? '')) !== '') $customer .= ' · @' . trim((string) $user['username']);
        $vi = new Language('vi');
        foreach (Helpers::adminTelegramIds($this->app) as $adminId) {
            $this->outbox->enqueueAdmin($adminId, 'order:' . $orderId . ':admin:' . $adminId, 'admin_order_success', [
                'customer' => $customer !== '' ? $customer : '-',
                'telegram_id' => (string) $user['telegram_id'],
                'product' => (string) ($variant['product_name'] ?: '-'),
                'quantity' => $quantity,
                'amount' => Helpers::money($total),
                'payment_method' => $vi->get('payment_method_wallet'),
                'code' => $orderCode,
                'time' => Helpers::dateTime($completedAt),
            ]);
        }
    }

    private function pendingDirectOrder(
        int $userId,
        array $variant,
        int $quantity,
        string $unitPrice,
        string $total,
        string $requestToken
    ): array {
        return $this->db->transaction(function (Database $db) use ($userId, $variant, $quantity, $unitPrice, $total, $requestToken): array {
            $user = $db->fetch('SELECT * FROM users WHERE id=? FOR UPDATE', [$userId]);
            $request = $db->fetch('SELECT * FROM purchase_requests WHERE request_token=? FOR UPDATE', [$requestToken]);
            if ($request !== null && $request['order_id'] !== null) {
                return $db->fetch('SELECT * FROM orders WHERE id=?', [$request['order_id']]);
            }
            if ($request === null) {
                $db->execute(
                    "INSERT INTO purchase_requests(request_token,user_id,variant_id,quantity,unit_price,status) VALUES(?,?,?,?,?,'processing')",
                    [$requestToken, $userId, $variant['id'], $quantity, $unitPrice]
                );
            }

            $balanceBefore = number_format((float) ($user['balance'] ?? 0), 2, '.', '');
            $walletAmount = number_format(min((float) $balanceBefore, (float) $total), 2, '.', '');
            if ((float) $walletAmount >= (float) $total) {
                throw new RuntimeException('Wallet balance changed during checkout');
            }
            $bankAmount = number_format((float) $total - (float) $walletAmount, 2, '.', '');
            $paymentMethod = (float) $walletAmount > 0 ? 'hybrid' : 'vietqr_direct';
            $registry = new PaymentCodeRegistry($db);
            $paymentCode = $registry->reserve('order');
            $orderCode = Helpers::orderCode();
            $expiresAt = date('Y-m-d H:i:s', time() + 900);
            $db->execute(
                "INSERT INTO orders(order_code,payment_code,user_id,variant_id,quantity,unit_price,product_name,request_token,amount,wallet_amount,payment_method,status,expires_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending',?)",
                [$orderCode, $paymentCode, $userId, $variant['id'], $quantity, $unitPrice, $variant['product_name'], $requestToken, $bankAmount, $walletAmount, $paymentMethod, $expiresAt]
            );
            $orderId = $db->lastInsertId();
            if ((float) $walletAmount > 0) {
                $balanceAfter = number_format((float) $balanceBefore - (float) $walletAmount, 2, '.', '');
                $db->execute('UPDATE users SET balance=? WHERE id=?', [$balanceAfter, $userId]);
                $db->execute(
                    "INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,reference_type,reference_id,reference_code,note)
                     VALUES(?,'purchase',?,?,?,'order',?,?,?)",
                    [$userId, -((float) $walletAmount), $balanceBefore, $balanceAfter, $orderId, $orderCode, 'hybrid_wallet_reserved']
                );
            }
            $registry->bind($paymentCode, 'order', $orderId);
            $db->execute("UPDATE purchase_requests SET status='completed',order_id=?,result_reason='qr_created' WHERE request_token=?", [$orderId, $requestToken]);
            return $db->fetch('SELECT * FROM orders WHERE id=?', [$orderId]);
        });
    }

    public function cancelOrder(int $chatId, int $messageId, int $userId, int $orderId, Language $lang): void
    {
        $result = $this->db->transaction(function (Database $db) use ($userId, $orderId): array {
            $order = $db->fetch('SELECT * FROM orders WHERE id=? AND user_id=? FOR UPDATE', [$orderId, $userId]);
            if ($order === null) return ['key' => 'order_cancel_not_found', 'amount' => '0.00'];
            if ($order['status'] !== 'pending' || $order['payment_method'] !== 'hybrid') {
                return ['key' => 'order_cancel_closed', 'amount' => '0.00'];
            }

            $walletAmount = number_format((float) $order['wallet_amount'], 2, '.', '');
            $user = $db->fetch('SELECT balance FROM users WHERE id=? FOR UPDATE', [$userId]);
            $balanceBefore = number_format((float) $user['balance'], 2, '.', '');
            $balanceAfter = number_format((float) $balanceBefore + (float) $walletAmount, 2, '.', '');
            if ((float) $walletAmount > 0) {
                $db->execute('UPDATE users SET balance=? WHERE id=?', [$balanceAfter, $userId]);
                $db->execute(
                    "INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,reference_type,reference_id,reference_code,note)
                     VALUES(?,'refund',?,?,?,'order',?,?,?)",
                    [$userId, $walletAmount, $balanceBefore, $balanceAfter, $orderId, $order['order_code'], 'hybrid_cancelled']
                );
            }
            $db->execute("UPDATE orders SET status='cancelled',result_reason='user_cancelled',wallet_credited=?,completed_at=NOW() WHERE id=?", [$walletAmount, $orderId]);
            return ['key' => 'order_cancelled_wallet_refunded', 'amount' => $walletAmount];
        });

        $this->telegram->editMessage($chatId, $messageId, $lang->get($result['key'], [
            'amount' => Helpers::money($result['amount']),
        ]), [[['text' => $lang->get('products_button'), 'callback_data' => 'products']]]);
    }

    private function recordPaymentMessage(
        int $orderId,
        int $userId,
        int $chatId,
        int $messageId,
        string $messageType,
        string $qrUrl
    ): void {
        $this->db->transaction(function (Database $db) use ($orderId, $userId, $chatId, $messageId, $messageType, $qrUrl): void {
            $order = $db->fetch('SELECT status FROM orders WHERE id=? AND user_id=? FOR UPDATE', [$orderId, $userId]);
            if ($order === null || $order['status'] !== 'pending') return;
            $db->execute(
                "UPDATE payment_message_links SET is_active=0,cleanup_status='superseded' WHERE request_type='order' AND request_id=? AND is_active=1",
                [$orderId]
            );
            $db->execute(
                "INSERT INTO payment_message_links(request_type,request_id,user_id,chat_id,message_id,message_type,qr_url)
                 VALUES('order',?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE request_type=VALUES(request_type),request_id=VALUES(request_id),user_id=VALUES(user_id),message_type=VALUES(message_type),qr_url=VALUES(qr_url),is_active=1,cleanup_status='pending',cleanup_attempts=0,last_error_code=NULL,cleaned_at=NULL",
                [$orderId, $userId, $chatId, $messageId, $messageType, $qrUrl]
            );
        });
    }
}
