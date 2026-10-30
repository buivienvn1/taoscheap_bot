<?php

$editId = max(0, (int) ($_GET['edit'] ?? 0));
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? 'save');
    $id = max(0, (int) ($_POST['id'] ?? 0));

    if ($action === 'delete' && $id > 0) {
        $result = $db->transaction(function (Database $db) use ($id): string {
            $product = $db->fetch('SELECT * FROM products WHERE id=? FOR UPDATE', [$id]);
            if ($product === null) {
                return 'Sản phẩm không tồn tại.';
            }

            $reference = $db->fetch(
                'SELECT 1 referenced
                 FROM variants v
                 WHERE v.product_id=?
                   AND (EXISTS (SELECT 1 FROM orders o WHERE o.variant_id=v.id)
                     OR EXISTS (SELECT 1 FROM purchase_requests pr WHERE pr.variant_id=v.id))
                 LIMIT 1',
                [$id]
            );
            if ($reference !== null) {
                $db->execute('UPDATE products SET status=0 WHERE id=?', [$id]);
                $db->execute('UPDATE variants SET status=0 WHERE product_id=?', [$id]);
                return 'Sản phẩm đã phát sinh giao dịch nên được chuyển sang trạng thái ẩn.';
            }

            $db->execute('DELETE s FROM stocks s JOIN variants v ON v.id=s.variant_id WHERE v.product_id=?', [$id]);
            $db->execute('UPDATE products SET source_variant_id=NULL WHERE id=?', [$id]);
            $db->execute('DELETE FROM variants WHERE product_id=?', [$id]);
            $db->execute('DELETE FROM products WHERE id=?', [$id]);
            return 'Đã xóa sản phẩm và kho chưa bán liên quan.';
        });
        redirectAdmin('products', $result);
    }

    if ($action === 'toggle' && $id > 0) {
        $db->transaction(function (Database $db) use ($id): void {
            $product = $db->fetch('SELECT status FROM products WHERE id=? FOR UPDATE', [$id]);
            if ($product === null) {
                return;
            }
            $status = (int) $product['status'] === 1 ? 0 : 1;
            $db->execute('UPDATE products SET status=? WHERE id=?', [$status, $id]);
            $db->execute('UPDATE variants SET status=? WHERE product_id=?', [$status, $id]);
        });
        redirectAdmin('products', 'Đã cập nhật trạng thái sản phẩm.');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $features = trim((string) ($_POST['features'] ?? ''));
    $price = number_format(max(0, (float) ($_POST['price'] ?? 0)), 2, '.', '');
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $fakeSold = max(0, (int) ($_POST['fake_sold'] ?? 0));
    $status = isset($_POST['status']) ? 1 : 0;
    if ($name === '') {
        redirectAdmin('products', 'Tên sản phẩm không hợp lệ.');
    }

    $db->transaction(function (Database $db) use ($id, $name, $description, $features, $price, $sortOrder, $fakeSold, $status): void {
        if ($id > 0) {
            $product = $db->fetch('SELECT * FROM products WHERE id=? FOR UPDATE', [$id]);
            if ($product === null) {
                return;
            }
            $db->execute(
                'UPDATE products SET name=?,description=?,features=?,sort_order=?,fake_sold=?,status=? WHERE id=?',
                [$name, $description, $features, $sortOrder, $fakeSold, $status, $id]
            );
            if ($product['source_variant_id'] !== null) {
                $db->execute(
                    'UPDATE variants SET name=?,price=?,status=?,sort_order=0 WHERE id=?',
                    [$name, $price, $status, $product['source_variant_id']]
                );
            }
            return;
        }

        $db->execute(
            'INSERT INTO products(name,description,features,sort_order,fake_sold,status) VALUES(?,?,?,?,?,?)',
            [$name, $description, $features, $sortOrder, $fakeSold, $status]
        );
        $productId = $db->lastInsertId();
        $db->execute(
            "INSERT INTO variants(product_id,name,price,supplier_type,provider_code,sort_order,status) VALUES(?,?,?,'manual',NULL,0,?)",
            [$productId, $name, $price, $status]
        );
        $variantId = $db->lastInsertId();
        $db->execute('UPDATE products SET source_variant_id=? WHERE id=?', [$variantId, $productId]);
        $db->execute("INSERT INTO supplier_sources(variant_id,source_type,priority,status) VALUES(?,'manual',0,1)", [$variantId]);
    });
    redirectAdmin('products', $id > 0 ? 'Đã cập nhật sản phẩm.' : 'Đã thêm sản phẩm.');
}

if ($editId > 0) {
    $editing = $db->fetch(
        'SELECT p.*,v.price FROM products p LEFT JOIN variants v ON v.id=p.source_variant_id WHERE p.id=?',
        [$editId]
    );
}

$products = $db->fetchAll(
    'SELECT p.*,v.price,
        (SELECT COUNT(*) FROM orders o JOIN variants ov ON ov.id=o.variant_id WHERE ov.product_id=p.id) order_count,
        (SELECT COUNT(*) FROM stocks s JOIN variants sv ON sv.id=s.variant_id WHERE sv.product_id=p.id AND s.is_sold=0) available
     FROM products p
     LEFT JOIN variants v ON v.id=p.source_variant_id
     ORDER BY p.sort_order,p.id'
);
$form = $editing ?? [];
?>
<h1>Sản phẩm</h1>
<div class="card">
    <h3><?= $editing !== null ? 'Sửa sản phẩm' : 'Thêm sản phẩm' ?></h3>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e($form['id'] ?? 0) ?>">
        <label for="product_name">Tên sản phẩm</label>
        <input id="product_name" name="name" value="<?= e($form['name'] ?? '') ?>" required>
        <label for="product_description">Mô tả</label>
        <small class="help-text"><?= e('Dùng <vi>Nội dung tiếng Việt</vi> và <en>English content</en>. Bên trong hỗ trợ các thẻ Telegram HTML như <b>, <i>, <u>, <s>, <code>, <pre>, <blockquote> và <a href="...">.') ?></small>
        <textarea id="product_description" name="description" rows="10"><?= e($form['description'] ?? '') ?></textarea>
        <label for="product_features">Hướng dẫn sử dụng</label>
        <small class="help-text"><?= e('Có thể dùng cùng cú pháp <vi>...</vi>, <en>...</en> và Telegram HTML.') ?></small>
        <textarea id="product_features" name="features" rows="6"><?= e($form['features'] ?? '') ?></textarea>
        <div class="form-grid">
            <div><label for="product_price">Giá</label><input id="product_price" name="price" type="number" min="0" step="0.01" value="<?= e($form['price'] ?? '') ?>" required></div>
            <div><label for="sort_order">Thứ tự</label><input id="sort_order" name="sort_order" type="number" value="<?= e($form['sort_order'] ?? 0) ?>"></div>
            <div><label for="fake_sold"><?= e($adminLanguage->get('admin_product_fake_sold')) ?></label><input id="fake_sold" name="fake_sold" type="number" min="0" step="1" value="<?= e($form['fake_sold'] ?? 0) ?>" title="<?= e($adminLanguage->get('admin_product_fake_sold_help')) ?>"></div>
        </div>
        <label class="inline-check"><input type="checkbox" name="status" <?= !isset($form['status']) || (int) $form['status'] === 1 ? 'checked' : '' ?>> Hiển thị sản phẩm</label>
        <div class="actions">
            <button type="submit"><?= $editing !== null ? 'Cập nhật sản phẩm' : 'Thêm sản phẩm' ?></button>
            <?php if ($editing !== null): ?><a class="button secondary" href="?page=products">Hủy sửa</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card table-wrap">
    <table>
        <thead><tr><th>Sản phẩm</th><th>Giá</th><th>Kho tay</th><th>Đơn</th><th><?= e($adminLanguage->get('admin_product_fake_sold')) ?></th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?= e($product['name']) ?></td>
                <td><?= e(Helpers::money($product['price'] ?? 0)) ?></td>
                <td><?= e($product['available']) ?></td>
                <td><?= e($product['order_count']) ?></td>
                <td><?= (int) ($product['fake_sold'] ?? 0) > 0 ? '+' . e($product['fake_sold']) : '0' ?></td>
                <td><span class="badge <?= (int) $product['status'] === 1 ? 'success' : 'muted' ?>"><?= (int) $product['status'] === 1 ? 'Đang bán' : 'Đã ẩn' ?></span></td>
                <td>
                    <div class="actions">
                        <a class="button" href="?page=products&edit=<?= e($product['id']) ?>">Sửa</a>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= e($product['id']) ?>">
                            <button type="submit" class="secondary"><?= (int) $product['status'] === 1 ? 'Ẩn' : 'Hiện' ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('Xóa sản phẩm chưa có đơn; nếu đã có đơn hệ thống sẽ chỉ ẩn. Tiếp tục?')">
                            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= e($product['id']) ?>">
                            <button type="submit" class="danger">Xóa</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
