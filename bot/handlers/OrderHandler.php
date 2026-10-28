<?php

declare(strict_types=1);

final class OrderHandler
{
    public function __construct(private Database $db, private Telegram $telegram, private VietQR $vietQr)
    {
    }

    public function buy(int $chatId, int $messageId, array $user, int $variantId, Language $lang): void
    {
        $variant = $this->db->fetch('SELECT v.*, p.name product_name FROM variants v JOIN products p ON p.id = v.product_id WHERE v.id = ? AND v.status = 1 AND p.status = 1', [$variantId]);
        if ($variant === null) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('product_unavailable'));
            return;
        }
        if ($variant['supplier_type'] !== 'manual') {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('api_supplier_pending'));
            return;
        }

        if ((float) $user['balance'] >= (float) $variant['price']) {
            $result = $this->purchaseWithWallet((int) $user['id'], $variant, $lang);
            $this->telegram->editMessage($chatId, $messageId, $result['message'], [[['text' => $lang->get('continue_buying'), 'callback_data' => 'products'], ['text' => $lang->get('home'), 'callback_data' => 'start']]]);
            return;
        }

        $paymentCode = $this->uniqueCode();
        $orderCode = Helpers::orderCode();
        $this->db->execute("INSERT INTO orders (order_code, payment_code, user_id, variant_id, amount, payment_method, status) VALUES (?, ?, ?, ?, ?, 'vietqr_direct', 'pending')", [$orderCode, $paymentCode, $user['id'], $variantId, $variant['price']]);
        $bank = $this->vietQr->transferDetails();
        $caption = '<blockquote><b>' . $lang->get('payment_title') . "</b></blockquote>\n\n"
            . $lang->get('product') . ': ' . Helpers::escape($variant['product_name'] . ' - ' . $variant['name']) . "\n"
            . $lang->get('amount') . ': <b>' . Helpers::money($variant['price']) . "</b>\n"
            . $lang->get('transfer_content') . ': <code>' . $paymentCode . "</code>\n"
            . '🏦 Bank: <b>' . Helpers::escape($bank['bank_id']) . '</b> | STK: <code>'
            . Helpers::escape($bank['account_number']) . '</code>';
        $keyboard = [[['text' => $lang->get('products_button'), 'callback_data' => 'products']]];

        try {
            $this->telegram->replaceWithPhoto($chatId, $messageId, $this->vietQr->imageUrl($paymentCode, (float) $variant['price']), $caption, $keyboard);
        } catch (Throwable $error) {
            error_log('Order QR delivery failed: ' . $error::class);
            $text = $caption . "\n\n<i>" . $lang->get('qr_unavailable') . "</i>\n\n"
                . '<b>' . $lang->get('bank') . ':</b> ' . Helpers::escape($bank['bank_id']) . "\n"
                . '<b>' . $lang->get('account_number') . ':</b> <code>' . Helpers::escape($bank['account_number']) . "</code>\n"
                . '<b>' . $lang->get('account_name') . ':</b> ' . Helpers::escape($bank['account_name']);
            $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
        }
    }

    private function purchaseWithWallet(int $userId, array $variant, Language $lang): array
    {
        return $this->db->transaction(function (Database $db) use ($userId, $variant, $lang): array {
            $user = $db->fetch('SELECT balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $stock = $db->fetch('SELECT id, content FROM stocks WHERE variant_id = ? AND is_sold = 0 ORDER BY id LIMIT 1 FOR UPDATE', [$variant['id']]);
            if ($stock === null) {
                return ['message' => $lang->get('just_out_of_stock')];
            }
            if ((float) $user['balance'] < (float) $variant['price']) {
                return ['message' => $lang->get('balance_changed')];
            }

            $orderCode = Helpers::orderCode();
            $db->execute("INSERT INTO orders (order_code, user_id, variant_id, amount, payment_method, status) VALUES (?, ?, ?, ?, 'wallet', 'processing')", [$orderCode, $userId, $variant['id'], $variant['price']]);
            $orderId = $db->lastInsertId();
            $db->execute('UPDATE users SET balance = balance - ? WHERE id = ?', [$variant['price'], $userId]);
            $db->execute('UPDATE stocks SET is_sold = 1, order_id = ?, sold_at = NOW() WHERE id = ?', [$orderId, $stock['id']]);
            $db->execute("UPDATE orders SET stock_content = ?, status = 'completed', paid_at = NOW(), completed_at = NOW() WHERE id = ?", [$stock['content'], $orderId]);
            $db->execute("INSERT INTO transactions (user_id, type, amount, reference_type, reference_id, note) VALUES (?, 'purchase', ?, 'order', ?, ?)", [$userId, -((float) $variant['price']), $orderId, $orderCode]);

            return ['message' => '<blockquote><b>' . $lang->get('purchase_success') . "</b></blockquote>\n\n"
                . $lang->get('order_code') . ': <code>' . $orderCode . "</code>\n"
                . $lang->get('product') . ': ' . Helpers::escape($variant['name']) . "\n\n"
                . $lang->get('delivery_info') . ":\n<code>" . Helpers::escape($stock['content']) . '</code>'];
        });
    }

    private function uniqueCode(): string
    {
        do {
            $code = Helpers::randomCode();
        } while ($this->db->fetch('SELECT id FROM orders WHERE payment_code = ?', [$code]) !== null);
        return $code;
    }
}
