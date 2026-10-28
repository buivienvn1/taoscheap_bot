<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $productId = (int) ($_POST['product_id'] ?? 0);
    $product = $db->fetch("SELECT p.source_variant_id FROM products p JOIN variants v ON v.id=p.source_variant_id WHERE p.id=? AND v.supplier_type='manual'", [$productId]);
    if ($product === null) redirectAdmin('stocks', 'Sản phẩm không dùng kho nhập tay.');
    $lines = preg_split('/\R/u', trim((string) ($_POST['content'] ?? ''))) ?: [];
    $count = 0;
    $db->transaction(function (Database $db) use ($product, $lines, &$count): void {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $db->execute('INSERT INTO stocks(variant_id,content) VALUES(?,?)', [$product['source_variant_id'], $line]);
            $count++;
        }
    });
    redirectAdmin('stocks', 'Đã nhập ' . $count . ' sản phẩm.');
}
$products = $db->fetchAll("SELECT p.id,p.name FROM products p JOIN variants v ON v.id=p.source_variant_id WHERE v.supplier_type='manual' ORDER BY p.sort_order,p.id");
$stocks = $db->fetchAll("SELECT p.id,p.name,COALESCE(SUM(s.is_sold=0),0) available,COALESCE(SUM(s.is_sold=1),0) sold FROM products p JOIN variants v ON v.id=p.source_variant_id LEFT JOIN stocks s ON s.variant_id=v.id GROUP BY p.id,p.name ORDER BY p.sort_order,p.id");
?>
<h1>Kho hàng</h1><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><select name="product_id" required><?php foreach($products as $p):?><option value="<?=e($p['id'])?>"><?=e($p['name'])?></option><?php endforeach;?></select><textarea name="content" rows="10" placeholder="Mỗi dòng là một key hoặc user|pass" required></textarea><button>Nhập kho</button></form></div>
<div class="card"><table><thead><tr><th>Sản phẩm</th><th>Còn</th><th>Đã bán</th></tr></thead><tbody><?php foreach($stocks as $s):?><tr><td><?=e($s['name'])?></td><td><?=e($s['available'])?></td><td><?=e($s['sold'])?></td></tr><?php endforeach;?></tbody></table></div>
