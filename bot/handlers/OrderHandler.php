<?php

declare(strict_types=1);

final class OrderHandler
{
    public function __construct(private Database $db, private Telegram $telegram, private VietQR $vietQr)
    {
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
        if ($variant['supplier_type'] !== 'manual') {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('api_supplier_pending'));
            return;
        }
        $available = $this->db->fetch('SELECT COUNT(*) total FROM stocks WHERE variant_id = ? AND is_sold = 0', [$variant['id']]);
        if ((int) $available['total'] < $quantity) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('quantity_unavailable', ['quantity' => $quantity]), [[
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
        $bank = $this->vietQr->transferDetails();
        $qrUrl = $this->vietQr->imageUrl($paymentCode, (float) $total);
        $content = '<blockquote><b>' . $lang->get('payment_title') . "</b></blockquote>\n\n"
            . $lang->get('payment_product_line', ['product' => Helpers::escape($variant['product_name'])]) . "\n"
            . $lang->get('payment_quantity_line', ['quantity' => $quantity]) . "\n"
            . $lang->get('payment_unit_price_line', ['amount' => Helpers::money($unitPrice)]) . "\n"
            . $lang->get('payment_total_line', ['amount' => Helpers::money($total)]) . "\n"
            . $lang->get('payment_bank_line', [
                'bank' => Helpers::escape($bank['bank_id']),
                'account' => Helpers::escape($bank['account_number']),
            ]) . "\n"
            . $lang->get('payment_content_line', ['content' => Helpers::escape($paymentCode)]) . "\n\n"
            . $lang->get('payment_instruction');
        $keyboard = [[['text' => $lang->get('back'), 'callback_data' => 'product:' . $productId]]];
        try {
            $photoResult = $this->telegram->replaceWithPhoto($chatId, $messageId, $qrUrl, $content, $keyboard);
        } catch (Throwable $error) {
            error_log('Order QR delivery failed: ' . $error::class);
            $fallbackKeyboard = [[['text' => $lang->get('open_qr'), 'url' => $qrUrl]], ...$keyboard];
            $this->telegram->editMessage($chatId, $messageId, $content . "\n\n<i>" . $lang->get('qr_unavailable') . '</i>', $fallbackKeyboard);
            return;
        }

        $newMessageId = (int) ($photoResult['result']['message_id'] ?? 0);
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
        return $this->db->transaction(function (Database $db) use ($userId, $variant, $quantity, $unitPrice, $requestToken, $lang): array {
            $existing = $db->fetch('SELECT * FROM purchase_requests WHERE request_token = ? FOR UPDATE', [$requestToken]);
            if ($existing !== null) {
                if ($existing['order_id'] !== null) return $this->walletOrderMessage($db, (int) $existing['order_id'], $lang);
                return ['message' => $lang->get($existing['result_reason'] === 'out_of_stock' ? 'just_out_of_stock' : 'request_processing')];
            }
            $db->execute(
                "INSERT INTO purchase_requests (request_token,user_id,variant_id,quantity,unit_price,status) VALUES (?,?,?,?,?,'processing')",
                [$requestToken, $userId, $variant['id'], $quantity, $unitPrice]
            );
            $user = $db->fetch('SELECT balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $currentVariant = $db->fetch(
                'SELECT v.price, v.status, p.status product_status FROM variants v JOIN products p ON p.id=v.product_id WHERE v.id=? FOR UPDATE',
                [$variant['id']]
            );
            if ($currentVariant === null || !(int) $currentVariant['status'] || !(int) $currentVariant['product_status']) {
                $db->execute("UPDATE purchase_requests SET status='failed',result_reason='product_unavailable' WHERE request_token=?", [$requestToken]);
                return ['message' => $lang->get('product_unavailable')];
            }
            if (number_format((float) $currentVariant['price'], 2, '.', '') !== $unitPrice) {
                $db->execute("UPDATE purchase_requests SET status='failed',result_reason='price_changed' WHERE request_token=?", [$requestToken]);
                return ['message' => $lang->get('price_changed')];
            }
            $total = number_format((float) $unitPrice * $quantity, 2, '.', '');
            if ((float) $user['balance'] < (float) $total) {
                $db->execute("UPDATE purchase_requests SET status='failed',result_reason='balance_changed' WHERE request_token=?", [$requestToken]);
                return ['message' => $lang->get('balance_changed')];
            }
            $stocks = $db->fetchAll(
                'SELECT id,content FROM stocks WHERE variant_id=? AND is_sold=0 ORDER BY id LIMIT ' . $quantity . ' FOR UPDATE',
                [$variant['id']]
            );
            if (count($stocks) !== $quantity) {
                $db->execute("UPDATE purchase_requests SET status='failed',result_reason='out_of_stock' WHERE request_token=?", [$requestToken]);
                return ['message' => $lang->get('just_out_of_stock')];
            }
            $orderCode = Helpers::orderCode();
            $db->execute(
                "INSERT INTO orders (order_code,user_id,variant_id,quantity,unit_price,product_name,request_token,amount,payment_method,status,paid_at)
                 VALUES (?,?,?,?,?,?,?,?, 'wallet','processing',NOW())",
                [$orderCode, $userId, $variant['id'], $quantity, $unitPrice, $variant['product_name'], $requestToken, $total]
            );
            $orderId = $db->lastInsertId();
            $contents = [];
            foreach ($stocks as $stock) {
                $db->execute('UPDATE stocks SET is_sold=1,order_id=?,sold_at=NOW() WHERE id=? AND is_sold=0', [$orderId, $stock['id']]);
                $db->execute('INSERT INTO order_deliveries(order_id,stock_id,content) VALUES(?,?,?)', [$orderId, $stock['id'], $stock['content']]);
                $contents[] = $stock['content'];
            }
            $legacyContent = implode("\n", $contents);
            $db->execute('UPDATE users SET balance=balance-? WHERE id=?', [$total, $userId]);
            $db->execute("UPDATE orders SET stock_content=?,status='completed',result_reason='delivered',completed_at=NOW() WHERE id=?", [$legacyContent, $orderId]);
            $db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note) VALUES(?,'purchase',?,'order',?,?)", [$userId, -((float) $total), $orderId, $orderCode]);
            $db->execute("UPDATE purchase_requests SET status='completed',order_id=?,result_reason='delivered' WHERE request_token=?", [$orderId, $requestToken]);
            return $this->walletOrderMessage($db, $orderId, $lang);
        });
    }

    private function walletOrderMessage(Database $db, int $orderId, Language $lang): array
    {
        $order = $db->fetch('SELECT * FROM orders WHERE id=?', [$orderId]);
        $deliveries = $db->fetchAll('SELECT content FROM order_deliveries WHERE order_id=? ORDER BY id', [$orderId]);
        $deliveryText = '';
        foreach ($deliveries as $index => $delivery) {
            $deliveryText .= "\n<b>" . $lang->get('delivery_item', ['number' => $index + 1]) . ":</b>\n<code>" . Helpers::escape($delivery['content']) . '</code>';
        }
        return ['message' => '<blockquote><b>' . $lang->get('purchase_success') . "</b></blockquote>\n\n"
            . $lang->get('order_code') . ': <code>' . Helpers::escape($order['order_code']) . "</code>\n"
            . $lang->get('product') . ': ' . Helpers::escape($order['product_name']) . "\n"
            . $lang->get('wallet_purchase_quantity', ['quantity' => (int) $order['quantity']]) . "\n"
            . $lang->get('amount') . ': <b>' . Helpers::money($order['amount']) . "</b>\n"
            . $deliveryText];
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
            $db->fetch('SELECT id FROM users WHERE id=? FOR UPDATE', [$userId]);
            $request = $db->fetch('SELECT * FROM purchase_requests WHERE request_token=? FOR UPDATE', [$requestToken]);
            if ($request !== null && $request['order_id'] !== null) {
                return $db->fetch('SELECT * FROM orders WHERE id=?', [$request['order_id']]);
            }
            $order = $db->fetch(
                "SELECT * FROM orders WHERE user_id=? AND variant_id=? AND quantity=? AND unit_price=?
                 AND payment_method='vietqr_direct' AND status='pending' ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [$userId, $variant['id'], $quantity, $unitPrice]
            );
            if ($order !== null) return $order;
            if ($request === null) {
                $db->execute(
                    "INSERT INTO purchase_requests(request_token,user_id,variant_id,quantity,unit_price,status) VALUES(?,?,?,?,?,'processing')",
                    [$requestToken, $userId, $variant['id'], $quantity, $unitPrice]
                );
            }
            do {
                $paymentCode = Helpers::randomCode('DONATE');
                $exists = $db->fetch('SELECT id FROM orders WHERE payment_code=?', [$paymentCode]) !== null
                    || $db->fetch('SELECT id FROM deposits WHERE code=?', [$paymentCode]) !== null;
            } while ($exists);
            $orderCode = Helpers::orderCode();
            $db->execute(
                "INSERT INTO orders(order_code,payment_code,user_id,variant_id,quantity,unit_price,product_name,request_token,amount,payment_method,status)
                 VALUES(?,?,?,?,?,?,?,?,?,'vietqr_direct','pending')",
                [$orderCode, $paymentCode, $userId, $variant['id'], $quantity, $unitPrice, $variant['product_name'], $requestToken, $total]
            );
            $orderId = $db->lastInsertId();
            $db->execute("UPDATE purchase_requests SET status='completed',order_id=?,result_reason='qr_created' WHERE request_token=?", [$orderId, $requestToken]);
            return $db->fetch('SELECT * FROM orders WHERE id=?', [$orderId]);
        });
    }
}
