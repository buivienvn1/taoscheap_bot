<?php

declare(strict_types=1);

final class ProductHandler
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function list(int $chatId, int $messageId): void
    {
        $products = $this->db->fetchAll("SELECT p.id, p.name, SUM(CASE WHEN v.supplier_type = 'api' THEN 1 ELSE COALESCE(s.available, 0) END) stock FROM products p LEFT JOIN variants v ON v.product_id = p.id AND v.status = 1 LEFT JOIN (SELECT variant_id, COUNT(*) available FROM stocks WHERE is_sold = 0 GROUP BY variant_id) s ON s.variant_id = v.id WHERE p.status = 1 GROUP BY p.id ORDER BY p.sort_order, p.id LIMIT 50");
        $text = "<b>DANH SÁCH SẢN PHẨM</b>\n\n";
        $buttons = [];
        foreach ($products as $index => $product) {
            $number = $index + 1;
            $stock = (int) $product['stock'];
            $text .= $number . '. ' . Helpers::escape($product['name']) . ' - ' . ($stock > 0 ? $stock . ' sẵn có' : 'Hết hàng') . "\n";
            $buttons[] = ['text' => (string) $number, 'callback_data' => 'product:' . $product['id']];
        }
        if ($products === []) {
            $text .= 'Chưa có sản phẩm.';
        }
        $keyboard = array_chunk($buttons, 5);
        $keyboard[] = [['text' => '⬅ Quay lại', 'callback_data' => 'start'], ['text' => '🔄 Làm mới', 'callback_data' => 'products']];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function detail(int $chatId, int $messageId, int $productId): void
    {
        $product = $this->db->fetch('SELECT * FROM products WHERE id = ? AND status = 1', [$productId]);
        if ($product === null) {
            $this->list($chatId, $messageId);
            return;
        }
        $variants = $this->db->fetchAll(
            "SELECT v.*, CASE WHEN v.supplier_type = 'api' THEN 1 ELSE COUNT(s.id) END stock
             FROM variants v
             LEFT JOIN stocks s ON s.variant_id = v.id AND s.is_sold = 0
             WHERE v.product_id = ? AND v.status = 1
             GROUP BY v.id
             ORDER BY v.sort_order, v.id",
            [$productId]
        );
        $text = '<b>CHI TIẾT SẢN PHẨM</b>' . "\n\n<b>Tên:</b> " . Helpers::escape($product['name']) . "\n" . Helpers::escape($product['description']);
        if ($product['features']) {
            $text .= "\n\n<b>Tính năng nổi bật 🌟</b>\n" . Helpers::escape($product['features']);
        }
        $text .= "\n\n<b>Gói sản phẩm:</b>\n";
        $keyboard = [];
        foreach ($variants as $index => $variant) {
            $stock = (int) $variant['stock'];
            $text .= ($index + 1) . '. ' . Helpers::escape($variant['name']) . "\n↳ Giá: " . Helpers::money($variant['price']) . "\n↳ Kho: " . $stock . "\n\n";
            if ($stock > 0) {
                $keyboard[] = [['text' => 'Mua ' . $variant['name'], 'callback_data' => 'buy:' . $variant['id']]];
            }
        }
        $keyboard[] = [['text' => '⬅ Quay lại', 'callback_data' => 'products'], ['text' => '🔄 Làm mới', 'callback_data' => 'product:' . $productId]];
        $this->telegram->editMessage($chatId, $messageId, trim($text), $keyboard);
    }
}
