<?php

declare(strict_types=1);

final class ProductHandler
{
    private const MAX_QUANTITY = 100;

    public function __construct(
        private Database $db,
        private Telegram $telegram,
        private SupplierCatalogService $supplierCatalog
    ) {
    }

    public function list(int $chatId, int $messageId, Language $lang): void
    {
        $products = $this->db->fetchAll(
            "SELECT p.id,p.name,v.id variant_id,v.price
             FROM products p
             JOIN variants v ON v.id=p.source_variant_id AND v.status=1
             WHERE p.status=1
             ORDER BY p.sort_order,p.id LIMIT 50"
        );
        $stockMap = $this->supplierCatalog->variantStockMap(array_map(static fn (array $product): int => (int) $product['variant_id'], $products));
        $text = '<blockquote><b>' . $lang->get('products_title') . "</b></blockquote>\n\n";
        $buttons = [];
        foreach ($products as $index => $product) {
            $number = $index + 1;
            $stock = $stockMap[(int) $product['variant_id']]['available'];
            $text .= $number . '. <b>' . Helpers::escape($product['name']) . "</b>\n"
                . $lang->get('product_item_sub', [
                    'price' => Helpers::money($product['price']),
                    'count' => $stock,
                ]) . "\n\n";
            $buttons[] = ['text' => (string) $number, 'callback_data' => 'product:' . $product['id']];
        }
        $text .= $products === [] ? $lang->get('empty_products') : "\n<i>" . $lang->get('product_list_hint') . '</i>';
        $keyboard = array_chunk($buttons, 5);
        $keyboard[] = [
            ['text' => $lang->get('back'), 'callback_data' => 'start'],
            ['text' => $lang->get('refresh'), 'callback_data' => 'products'],
        ];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function detail(int $chatId, int $messageId, int $userId, int $productId, Language $lang): void
    {
        $selection = $this->selection($userId, $messageId, $productId);
        $this->render($chatId, $messageId, $userId, $productId, (int) $selection['quantity'], (int) $selection['version'], $lang);
    }

    public function changeQuantity(
        int $chatId,
        int $messageId,
        int $userId,
        int $productId,
        string $operation,
        int $version,
        Language $lang
    ): void {
        $selection = $this->db->transaction(function (Database $db) use ($userId, $messageId, $productId, $operation, $version): ?array {
            $current = $db->fetch(
                'SELECT * FROM checkout_selections WHERE user_id = ? AND message_id = ? FOR UPDATE',
                [$userId, $messageId]
            );
            if ($current === null || (int) $current['product_id'] !== $productId || (int) $current['version'] !== $version) {
                return null;
            }
            $quantity = (int) $current['quantity'];
            $quantity = match ($operation) {
                'inc' => $quantity + 1,
                'dec' => $quantity - 1,
                'five' => 5,
                'ten' => 10,
                default => $quantity,
            };
            $quantity = max(1, min(self::MAX_QUANTITY, $quantity));
            $db->execute(
                'UPDATE checkout_selections SET quantity = ?, version = version + 1 WHERE user_id = ? AND message_id = ?',
                [$quantity, $userId, $messageId]
            );
            return ['quantity' => $quantity, 'version' => $version + 1];
        });
        if ($selection === null) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('selection_expired'), [
                [['text' => $lang->get('products_button'), 'callback_data' => 'products']],
            ]);
            return;
        }
        $this->render($chatId, $messageId, $userId, $productId, (int) $selection['quantity'], (int) $selection['version'], $lang);
    }

    public function subscribeToStock(int $chatId, int $messageId, int $userId, int $productId, Language $lang): void
    {
        $product = $this->db->fetch(
            "SELECT p.id,v.id variant_id
             FROM products p
             JOIN variants v ON v.id=p.source_variant_id AND v.status=1
             WHERE p.id=? AND p.status=1",
            [$productId]
        );
        if ($product === null) {
            $this->list($chatId, $messageId, $lang);
            return;
        }
        $stock = $this->supplierCatalog->variantStock((int) $product['variant_id']);
        if ($stock['available'] === 0) {
            $this->db->execute(
                "INSERT INTO stock_subscriptions(user_id,product_id,status,notified_at)
                 VALUES(?,?,'pending',NULL)
                 ON DUPLICATE KEY UPDATE status='pending',notified_at=NULL,created_at=CURRENT_TIMESTAMP",
                [$userId, $productId]
            );
        }
        $this->detail($chatId, $messageId, $userId, $productId, $lang);
    }

    private function selection(int $userId, int $messageId, int $productId): array
    {
        return $this->db->transaction(function (Database $db) use ($userId, $messageId, $productId): array {
            $current = $db->fetch(
                'SELECT * FROM checkout_selections WHERE user_id = ? AND message_id = ? FOR UPDATE',
                [$userId, $messageId]
            );
            if ($current !== null && (int) $current['product_id'] === $productId) {
                return $current;
            }
            $version = $current === null ? 1 : (int) $current['version'] + 1;
            $db->execute(
                'INSERT INTO checkout_selections (user_id, message_id, product_id, quantity, version) VALUES (?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), quantity = 1, version = VALUES(version)',
                [$userId, $messageId, $productId, $version]
            );
            return ['quantity' => 1, 'version' => $version];
        });
    }

    private function render(
        int $chatId,
        int $messageId,
        int $userId,
        int $productId,
        int $quantity,
        int $version,
        Language $lang
    ): void {
        $product = $this->db->fetch(
            "SELECT p.id,p.name,p.description,p.features,v.id variant_id,v.price,COALESCE(sold.total,0) sold
             FROM products p
             JOIN variants v ON v.id=p.source_variant_id AND v.status=1
             LEFT JOIN (SELECT variant_id,COUNT(*) total FROM stocks WHERE is_sold=1 GROUP BY variant_id) sold ON sold.variant_id=v.id
             WHERE p.id=? AND p.status=1",
            [$productId]
        );
        if ($product === null) {
            $this->list($chatId, $messageId, $lang);
            return;
        }
        $stockInfo = $this->supplierCatalog->variantStock((int) $product['variant_id']);
        $stock = $stockInfo['available'];
        $displayQuantity = $stock > 0 ? min($quantity, $stock) : 1;
        if ($displayQuantity !== $quantity) {
            $this->db->execute(
                'UPDATE checkout_selections SET quantity=? WHERE user_id=? AND message_id=? AND product_id=? AND version=?',
                [$displayQuantity, $userId, $messageId, $productId, $version]
            );
            $quantity = $displayQuantity;
        }
        $total = number_format((float) $product['price'] * $quantity, 2, '.', '');
        $descriptionParts = array_values(array_filter([
            Helpers::extractMultilingualText((string) $product['description'], $lang->code()),
            Helpers::extractMultilingualText((string) $product['features'], $lang->code()),
        ], static fn (string $value): bool => trim($value) !== ''));
        $description = Helpers::sanitizeTelegramHtml(implode("\n", $descriptionParts));
        if ($description === '') {
            $description = '╰ ' . $lang->get('description_empty');
        }
        $text = '<blockquote><b>' . $lang->get('product_detail') . "</b></blockquote>\n\n"
            . '<b>📦 ' . Helpers::escape($product['name']) . "</b>\n"
            . '╰ ' . $lang->get('price') . ': <b>' . Helpers::money($product['price']) . "</b>\n"
            . '╰ ' . $lang->get('stock') . ': <b>' . $stock . "</b>\n"
            . '╰ ' . $lang->get('sold') . ': <b>' . (int) $product['sold'] . "</b>\n\n"
            . '<b>' . $lang->get('description') . "</b>\n" . $description . "\n\n"
            . '<b>' . $lang->get('order_details') . "</b>\n"
            . '╰ ' . $lang->get('quantity') . ': <b>' . $quantity . "</b>\n"
            . '╰ ' . $lang->get('total') . ': <b>' . Helpers::money($total) . '</b>';

        $keyboard = [];
        if ($stock > 0) {
            $base = 'qty:' . $productId . ':' . $version . ':';
            $keyboard[] = [
                ['text' => '➖', 'callback_data' => $base . 'dec'],
                ['text' => $lang->get('quantity_button', ['quantity' => $quantity]), 'callback_data' => $base . 'show'],
                ['text' => '➕', 'callback_data' => $base . 'inc'],
            ];
            $quick = [];
            if ($stock >= 5) $quick[] = ['text' => $lang->get('select_five'), 'callback_data' => $base . 'five'];
            if ($stock >= 10) $quick[] = ['text' => $lang->get('select_ten'), 'callback_data' => $base . 'ten'];
            if ($quick !== []) $keyboard[] = $quick;
            $keyboard[] = [[
                'text' => $lang->get('checkout'),
                'callback_data' => 'checkout:' . $productId . ':' . $version,
            ]];
        } else {
            $subscription = $this->db->fetch(
                "SELECT id FROM stock_subscriptions WHERE user_id=? AND product_id=? AND status='pending'",
                [$userId, $productId]
            );
            $keyboard[] = [[
                'text' => $lang->get($subscription === null ? 'notify_stock_button' : 'notify_stock_subscribed'),
                'callback_data' => 'notify_stock:' . $productId,
            ]];
        }
        $keyboard[] = [
            ['text' => $lang->get('back'), 'callback_data' => 'products'],
            ['text' => $lang->get('refresh'), 'callback_data' => 'product:' . $productId],
        ];
        $messageResult = $this->telegram->editMessage($chatId, $messageId, trim($text), $keyboard);
        $newMessageId = (int) ($messageResult['result']['message_id'] ?? 0);
        if ($newMessageId > 0 && $newMessageId !== $messageId) {
            $this->db->execute(
                'INSERT INTO checkout_selections (user_id,message_id,product_id,quantity,version)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE product_id=VALUES(product_id),quantity=VALUES(quantity),version=VALUES(version)',
                [$userId, $newMessageId, $productId, $quantity, $version]
            );
        }
    }
}
