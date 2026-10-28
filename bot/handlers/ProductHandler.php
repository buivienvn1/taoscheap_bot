<?php

declare(strict_types=1);

final class ProductHandler
{
    public function __construct(private Database $db, private Telegram $telegram)
    {
    }

    public function list(int $chatId, int $messageId, Language $lang): void
    {
        $products = $this->db->fetchAll("SELECT p.id, p.name, SUM(CASE WHEN v.supplier_type = 'api' THEN 1 ELSE COALESCE(s.available, 0) END) stock FROM products p LEFT JOIN variants v ON v.product_id = p.id AND v.status = 1 LEFT JOIN (SELECT variant_id, COUNT(*) available FROM stocks WHERE is_sold = 0 GROUP BY variant_id) s ON s.variant_id = v.id WHERE p.status = 1 GROUP BY p.id ORDER BY p.sort_order, p.id LIMIT 50");
        $text = '<blockquote><b>' . $lang->get('products_title') . "</b></blockquote>\n\n";
        $buttons = [];
        foreach ($products as $index => $product) {
            $number = $index + 1;
            $stock = (int) $product['stock'];
            $text .= $number . '. <b>' . Helpers::escape($product['name']) . '</b> - '
                . $lang->get('product_stock', ['count' => $stock]) . "\n";
            $buttons[] = ['text' => (string) $number, 'callback_data' => 'product:' . $product['id']];
        }
        if ($products === []) {
            $text .= $lang->get('empty_products');
        } else {
            $text .= "\n<i>" . $lang->get('product_list_hint') . '</i>';
        }
        $keyboard = array_chunk($buttons, 5);
        $keyboard[] = [['text' => $lang->get('back'), 'callback_data' => 'start'], ['text' => $lang->get('refresh'), 'callback_data' => 'products']];
        $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }

    public function detail(int $chatId, int $messageId, int $productId, Language $lang): void
    {
        $product = $this->db->fetch('SELECT * FROM products WHERE id = ? AND status = 1', [$productId]);
        if ($product === null) {
            $this->list($chatId, $messageId, $lang);
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
        $text = '<blockquote><b>' . $lang->get('product_detail') . "</b></blockquote>\n\n<b>" . $lang->get('name') . ':</b> ' . Helpers::escape($product['name']) . "\n" . Helpers::escape($product['description']);
        if ($product['features']) {
            $text .= "\n\n<b>" . $lang->get('features') . "</b>\n" . Helpers::escape($product['features']);
        }
        $text .= "\n\n<b>" . $lang->get('product_packages') . ":</b>\n";
        $keyboard = [];
        foreach ($variants as $index => $variant) {
            $stock = (int) $variant['stock'];
            $text .= ($index + 1) . '. ' . Helpers::escape($variant['name']) . "\n↳ " . $lang->get('price') . ': ' . Helpers::money($variant['price']) . "\n↳ " . $lang->get('stock') . ': ' . $stock . "\n\n";
            if ($stock > 0) {
                $keyboard[] = [['text' => $lang->get('buy', ['name' => $variant['name']]), 'callback_data' => 'buy:' . $variant['id']]];
            }
        }
        $keyboard[] = [['text' => $lang->get('back'), 'callback_data' => 'products'], ['text' => $lang->get('refresh'), 'callback_data' => 'product:' . $productId]];
        $this->telegram->editMessage($chatId, $messageId, trim($text), $keyboard);
    }
}
