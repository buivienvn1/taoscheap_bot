<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $features = trim((string) ($_POST['features'] ?? ''));
    $price = number_format(max(0, (float) ($_POST['price'] ?? 0)), 2, '.', '');
    $supplierType = ($_POST['supplier_type'] ?? 'manual') === 'api' ? 'api' : 'manual';
    $providerCode = trim((string) ($_POST['provider_code'] ?? '')) ?: null;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $status = isset($_POST['status']) ? 1 : 0;
    if ($name === '') redirectAdmin('products', 'Tên sản phẩm không hợp lệ.');
    $db->transaction(function (Database $db) use ($id, $name, $description, $features, $price, $supplierType, $providerCode, $sortOrder, $status): void {
        if ($id > 0) {
            $product = $db->fetch('SELECT * FROM products WHERE id=? FOR UPDATE', [$id]);
            if ($product === null) return;
            $db->execute('UPDATE products SET name=?,description=?,features=?,sort_order=?,status=? WHERE id=?', [$name, $description, $features, $sortOrder, $status, $id]);
            if ($product['source_variant_id'] !== null) {
                $db->execute('UPDATE variants SET name=?,price=?,supplier_type=?,provider_code=?,status=?,sort_order=0 WHERE id=?', [$name, $price, $supplierType, $providerCode, $status, $product['source_variant_id']]);
            }
            return;
        }
        $db->execute('INSERT INTO products(name,description,features,sort_order,status) VALUES(?,?,?,?,?)', [$name, $description, $features, $sortOrder, $status]);
        $productId = $db->lastInsertId();
        $db->execute('INSERT INTO variants(product_id,name,price,supplier_type,provider_code,sort_order,status) VALUES(?,?,?,?,?,0,?)', [$productId, $name, $price, $supplierType, $providerCode, $status]);
        $db->execute('UPDATE products SET source_variant_id=? WHERE id=?', [$db->lastInsertId(), $productId]);
    });
    redirectAdmin('products', 'Đã lưu sản phẩm.');
}
$products = $db->fetchAll("SELECT p.*,v.price,v.supplier_type,v.provider_code FROM products p LEFT JOIN variants v ON v.id=p.source_variant_id ORDER BY p.sort_order,p.id");
?>
<h1>Sản phẩm</h1>
<div class="card"><h3>Thêm sản phẩm</h3><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input name="name" placeholder="Tên sản phẩm" required><textarea name="description" placeholder="Mô tả"></textarea><textarea name="features" placeholder="Hướng dẫn sử dụng"></textarea><input name="price" type="number" min="0" step="0.01" placeholder="Giá" required><select name="supplier_type"><option value="manual">Kho nhập tay</option><option value="api">API nhà cung cấp</option></select><input name="provider_code" placeholder="Mã API (nếu có)"><input name="sort_order" type="number" value="0"><label><input type="checkbox" name="status" checked style="width:auto"> Hiển thị</label><br><button>Lưu sản phẩm</button></form></div>
<div class="card"><table><thead><tr><th>Sản phẩm</th><th>Giá</th><th>Nguồn</th><th>Trạng thái</th></tr></thead><tbody><?php foreach($products as $p):?><tr><td><?=e($p['name'])?></td><td><?=e(Helpers::money($p['price']??0))?></td><td><?=e($p['supplier_type']??'-')?></td><td><?=$p['status']?'Bật':'Tắt'?></td></tr><?php endforeach;?></tbody></table></div>
