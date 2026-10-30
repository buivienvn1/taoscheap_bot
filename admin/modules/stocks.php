<?php

$stockLanguage = $adminLanguage ?? new Language('vi');
$stockText = static fn (string $key): string => $stockLanguage->get($key);
$sourceFingerprint = static function (array $rows): string {
    $data = [];
    foreach ($rows as $row) {
        $data[] = [(int) $row['priority'], (string) $row['source_type'], (string) ($row['provider_key'] ?? ''), (string) ($row['remote_product_code'] ?? ''), (int) $row['status']];
    }
    return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
};

$filterProductId = max(0, (int) ($_GET['product_id'] ?? 0));
$filterStatus = (string) ($_GET['status'] ?? 'available');
if (!in_array($filterStatus, ['available', 'sold', 'all'], true)) {
    $filterStatus = 'available';
}
$editId = max(0, (int) ($_GET['edit'] ?? 0));
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? 'import');

    if ($action === 'refresh_inventory') {
        $result = $supplierCatalog->refreshInventory();
        redirectAdmin('stocks', $stockText($result['success'] ? 'admin_stock_refresh_success' : 'admin_stock_refresh_partial'));
    }

    if ($action === 'save_sources') {
        $productId = max(0, (int) ($_POST['product_id'] ?? 0));
        $product = $db->fetch('SELECT source_variant_id FROM products WHERE id=?', [$productId]);
        if ($product === null || $product['source_variant_id'] === null) redirectAdmin('stocks', 'Sản phẩm không hợp lệ.');
        $variantId = (int) $product['source_variant_id'];
        $providerNames = $supplierRegistry->names();
        $primaryProvider = trim((string) ($_POST['primary_provider'] ?? ''));
        $backupProvider = trim((string) ($_POST['backup_provider'] ?? ''));
        $primaryCode = trim((string) ($_POST['primary_code'] ?? ''));
        $backupCode = trim((string) ($_POST['backup_code'] ?? ''));
        if (($primaryProvider !== '' && !isset($providerNames[$primaryProvider]))
            || ($backupProvider !== '' && !isset($providerNames[$backupProvider]))) {
            redirectAdmin('stocks', 'Nhà cung cấp không hợp lệ.');
        }
        if ($primaryProvider !== '' && $primaryCode !== '' && $primaryProvider === $backupProvider && $primaryCode === $backupCode) {
            redirectAdmin('stocks', $stockText('admin_supplier_source_duplicate'));
        }
        foreach ([[$primaryProvider, $primaryCode], [$backupProvider, $backupCode]] as [$provider, $code]) {
            if ($provider === '' || $code === '') continue;
            $known = $db->fetch('SELECT 1 FROM supplier_products WHERE provider_key=? AND remote_product_code=? AND is_active=1', [$provider, $code]);
            if ($known === null && !isset($_POST['allow_manual_code'])) redirectAdmin('stocks', $stockText('admin_stock_code_not_synced'));
        }
        $expectedVersion = trim((string) ($_POST['source_version'] ?? ''));
        $currentSources = $db->fetchAll('SELECT source_type,provider_key,remote_product_code,priority,status FROM supplier_sources WHERE variant_id=? ORDER BY priority,id', [$variantId]);
        if (!hash_equals($sourceFingerprint($currentSources), $expectedVersion)) redirectAdmin('stocks', $stockText('admin_stock_source_conflict'));
        $sources = [
            ['manual', null, null, 0, isset($_POST['manual_enabled']) ? 1 : 0],
            ['api', $primaryProvider, $primaryCode, 1, 1],
            ['api', $backupProvider, $backupCode, 2, 1],
        ];
        $db->transaction(function (Database $db) use ($variantId, $sources): void {
            foreach ($sources as [$type, $provider, $code, $priority, $enabled]) {
                if ($type === 'api' && ($provider === '' || $code === '')) $enabled = 0;
                $existing = $db->fetch('SELECT id FROM supplier_sources WHERE variant_id=? AND priority=? FOR UPDATE', [$variantId, $priority]);
                if ($existing === null) {
                    $db->execute('INSERT INTO supplier_sources(variant_id,source_type,provider_key,remote_product_code,priority,status) VALUES(?,?,?,?,?,?)', [$variantId, $type, $provider ?: null, $code ?: null, $priority, $enabled]);
                    continue;
                }
                $db->execute('UPDATE supplier_sources SET source_type=?,provider_key=?,remote_product_code=?,status=? WHERE id=?', [$type, $provider ?: null, $code ?: null, $enabled, $existing['id']]);
            }
        });
        redirectAdmin('stocks', 'Đã cập nhật nguồn hàng.');
    }

    if ($action === 'update') {
        $stockId = max(0, (int) ($_POST['stock_id'] ?? 0));
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($stockId === 0 || $content === '') {
            redirectAdmin('stocks', 'Nội dung kho không hợp lệ.');
        }
        $updated = $db->execute('UPDATE stocks SET content=? WHERE id=? AND is_sold=0', [$content, $stockId]);
        redirectAdmin('stocks', $updated === 1 ? 'Đã cập nhật dòng kho.' : 'Không thể sửa dòng kho đã bán hoặc không tồn tại.');
    }

    if ($action === 'delete') {
        $stockId = max(0, (int) ($_POST['stock_id'] ?? 0));
        $deleted = $db->execute('DELETE FROM stocks WHERE id=? AND is_sold=0', [$stockId]);
        redirectAdmin('stocks', $deleted === 1 ? 'Đã xóa dòng kho.' : 'Không thể xóa dòng kho đã bán hoặc không tồn tại.');
    }

    if ($action === 'delete_available') {
        $productId = max(0, (int) ($_POST['product_id'] ?? 0));
        $deleted = $db->execute(
            'DELETE s FROM stocks s JOIN variants v ON v.id=s.variant_id WHERE v.product_id=? AND s.is_sold=0',
            [$productId]
        );
        redirectAdmin('stocks', 'Đã xóa ' . $deleted . ' dòng kho chưa bán.');
    }

    $productId = max(0, (int) ($_POST['product_id'] ?? 0));
    $product = $db->fetch(
        "SELECT p.id,p.name,p.source_variant_id FROM products p JOIN variants v ON v.id=p.source_variant_id WHERE p.id=? AND EXISTS(SELECT 1 FROM supplier_sources ss WHERE ss.variant_id=v.id AND ss.source_type='manual' AND ss.status=1)",
        [$productId]
    );
    if ($product === null) {
        redirectAdmin('stocks', 'Sản phẩm không dùng kho nhập tay.');
    }

    $lines = preg_split('/\R/u', trim((string) ($_POST['content'] ?? ''))) ?: [];
    $uniqueLines = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $uniqueLines[$line] = true;
        }
    }

    $count = 0;
    $duplicate = 0;
    $db->transaction(function (Database $db) use ($product, $uniqueLines, &$count, &$duplicate): void {
        foreach (array_keys($uniqueLines) as $line) {
            $exists = $db->fetch('SELECT id FROM stocks WHERE variant_id=? AND content=? LIMIT 1', [$product['source_variant_id'], $line]);
            if ($exists !== null) {
                $duplicate++;
                continue;
            }
            $db->execute('INSERT INTO stocks(variant_id,content) VALUES(?,?)', [$product['source_variant_id'], $line]);
            $count++;
        }
    });

    $notified = 0;
    if ($count > 0) {
        $subscribers = $db->fetchAll(
            "SELECT ss.id,u.telegram_id,u.language
             FROM stock_subscriptions ss
             JOIN users u ON u.id=ss.user_id
             WHERE ss.product_id=? AND ss.status='pending'",
            [$productId]
        );
        foreach ($subscribers as $subscriber) {
            $languageCode = in_array($subscriber['language'], ['vi', 'en'], true) ? $subscriber['language'] : 'vi';
            $language = new Language($languageCode);
            $text = $language->get('restock_alert_message', [
                'product' => Helpers::escape((string) $product['name']),
            ]);
            try {
                $telegram->sendMessage((int) $subscriber['telegram_id'], $text, [[[
                    'text' => $language->get('view_product_button'),
                    'callback_data' => 'product:' . $productId,
                ]]]);
                $db->execute(
                    "UPDATE stock_subscriptions SET status='notified',notified_at=NOW() WHERE id=? AND status='pending'",
                    [$subscriber['id']]
                );
                $notified++;
            } catch (Throwable $error) {
                error_log('Restock notification failed: ' . $error::class);
            }
        }
    }
    redirectAdmin('stocks', 'Đã nhập ' . $count . ' dòng; bỏ qua ' . $duplicate . ' dòng trùng; đã báo ' . $notified . ' khách hàng.');
}

$products = $db->fetchAll(
    "SELECT p.id,p.name,p.source_variant_id FROM products p JOIN variants v ON v.id=p.source_variant_id ORDER BY p.sort_order,p.id"
);
$manualProducts = $db->fetchAll(
    "SELECT p.id,p.name FROM products p
     JOIN variants v ON v.id=p.source_variant_id
     JOIN supplier_sources ss ON ss.variant_id=v.id AND ss.source_type='manual' AND ss.status=1
     ORDER BY p.sort_order,p.id"
);
$sourceRows = $db->fetchAll('SELECT * FROM supplier_sources ORDER BY variant_id,priority,id');
$sourcesByVariant = [];
foreach ($sourceRows as $sourceRow) $sourcesByVariant[(int) $sourceRow['variant_id']][] = $sourceRow;
$providerNames = $supplierRegistry->names();
$remoteProducts = $db->fetchAll('SELECT provider_key,remote_product_code,product_name,stock,is_available,is_active,unit_price,currency FROM supplier_products WHERE is_active=1 ORDER BY provider_key,product_name');
$balanceRows = $db->fetchAll("SELECT provider_key,currency,amount FROM supplier_balances WHERE currency IN ('USD','USDT','VND')");
$balancesByProvider = [];
foreach ($balanceRows as $balanceRow) {
    $providerKey = (string) $balanceRow['provider_key'];
    $currency = strtoupper((string) $balanceRow['currency']);
    if ($currency === 'USD') {
        $currency = 'USDT';
    }
    $balancesByProvider[$providerKey][$currency] = (float) $balanceRow['amount'];
}
$remoteProductsByProvider = [];
$remoteProductsByKey = [];
foreach ($remoteProducts as $remoteProduct) {
    $providerKey = (string) $remoteProduct['provider_key'];
    $remoteCode = (string) $remoteProduct['remote_product_code'];
    $curr = strtoupper((string) ($remoteProduct['currency'] ?? 'USDT'));
    if ($curr === 'USD') {
        $curr = 'USDT';
    }
    $balance = (float) ($balancesByProvider[$providerKey][$curr] ?? 0.0);
    $remoteProduct['effective_stock'] = $supplierCatalog->effectiveStock($remoteProduct, $balance);
    $remoteProductsByProvider[$providerKey][] = $remoteProduct;
    $remoteProductsByKey[$providerKey . "\0" . $remoteCode] = $remoteProduct;
}
$summary = $db->fetchAll(
    'SELECT p.id,p.name,p.source_variant_id,p.fake_sold,COALESCE(sold.total,0) sold
     FROM products p
     JOIN variants v ON v.id=p.source_variant_id
     LEFT JOIN (SELECT variant_id,COALESCE(SUM(quantity),0) total FROM orders WHERE status=\'completed\' GROUP BY variant_id) sold ON sold.variant_id=v.id
     ORDER BY p.sort_order,p.id'
);
$summaryStock = $supplierCatalog->variantStockMap(array_map(static fn (array $item): int => (int) $item['source_variant_id'], $summary));
foreach ($summary as &$summaryItem) {
    $stock = $summaryStock[(int) $summaryItem['source_variant_id']];
    $summaryItem['manual_available'] = $stock['manual_available'];
    $summaryItem['api_available'] = $stock['api_available'];
    $summaryItem['api_provider_name'] = $stock['api_provider_name'];
    $summaryItem['available'] = $stock['available'];
}
unset($summaryItem);

if ($editId > 0) {
    $editing = $db->fetch(
        'SELECT s.id,s.content,p.name product_name FROM stocks s JOIN variants v ON v.id=s.variant_id JOIN products p ON p.id=v.product_id WHERE s.id=? AND s.is_sold=0',
        [$editId]
    );
}

$where = [];
$params = [];
if ($filterProductId > 0) {
    $where[] = 'p.id=?';
    $params[] = $filterProductId;
}
if ($filterStatus === 'available') {
    $where[] = 's.is_sold=0';
} elseif ($filterStatus === 'sold') {
    $where[] = 's.is_sold=1';
}
$sql = 'SELECT s.id,s.content,s.is_sold,s.order_id,s.created_at,s.sold_at,p.id product_id,p.name product_name,o.order_code
        FROM stocks s JOIN variants v ON v.id=s.variant_id JOIN products p ON p.id=v.product_id
        LEFT JOIN orders o ON o.id=s.order_id';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY s.id DESC LIMIT 300';
$stockRows = $db->fetchAll($sql, $params);
?>
<h1>Kho hàng</h1>
<div class="card">
    <div class="card-heading"><h3><?= e($stockText('admin_stock_overview')) ?></h3><form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="refresh_inventory"><button type="submit"><?= e($stockText('admin_stock_refresh_inventory')) ?></button></form></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= e($stockText('admin_stock_product')) ?></th><th><?= e($stockText('admin_stock_available')) ?></th><th><?= e($stockText('admin_stock_sold')) ?></th><th><?= e($stockText('admin_stock_action')) ?></th></tr></thead>
        <tbody><?php foreach ($summary as $item): ?><tr>
            <td><?= e($item['name']) ?></td><td><strong><?= e($item['available']) ?></strong><?php if ($item['api_provider_name'] !== null): ?> <small>(<?= e($item['api_provider_name']) ?>: <?= e($item['api_available']) ?>)</small><?php endif; ?><br><small><?= e($stockText('admin_stock_manual_count')) ?>: <?= e($item['manual_available']) ?></small></td><td><?= e($item['sold']) ?><?php if ((int) ($item['fake_sold'] ?? 0) > 0): ?> <small><?= e($stockText('admin_stock_fake_sold_badge', ['count' => (int) $item['fake_sold']])) ?></small><?php endif; ?></td>
            <td><form method="post" onsubmit="return confirm('<?= e($stockText('admin_stock_delete_confirm')) ?>')"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete_available"><input type="hidden" name="product_id" value="<?= e($item['id']) ?>"><button type="submit" class="danger"><?= e($stockText('admin_stock_delete_available')) ?></button></form></td>
        </tr><?php endforeach; ?><?php if ($summary === []): ?><tr><td colspan="4"><?= e($stockText('admin_stock_empty')) ?></td></tr><?php endif; ?></tbody>
    </table></div>
</div>
<div class="card">
    <h3>Cấu hình nguồn hàng</h3>
    <div class="table-wrap"><table><thead><tr><th>Sản phẩm</th><th>Ưu tiên nguồn</th><th>Thao tác</th></tr></thead><tbody>
    <?php foreach ($products as $product): $configured = $sourcesByVariant[(int) $product['source_variant_id']] ?? []; $byPriority = []; foreach ($configured as $source) $byPriority[(int) $source['priority']] = $source; $sourceFormId = 'source-form-' . (int) $product['id']; $primaryInputId = 'primary-code-' . (int) $product['id']; $backupInputId = 'backup-code-' . (int) $product['id']; ?>
    <tr>
        <td><?= e($product['name']) ?><form id="<?= e($sourceFormId) ?>" method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_sources"><input type="hidden" name="product_id" value="<?= e($product['id']) ?>"><input type="hidden" name="source_version" value="<?= e($sourceFingerprint($configured)) ?>"></form><a href="?page=suppliers&q=<?= rawurlencode((string) $product['name']) ?>"><?= e($stockText('admin_stock_compare_suppliers')) ?></a></td>
        <td>
            <label class="inline-check"><input form="<?= e($sourceFormId) ?>" type="checkbox" name="manual_enabled" <?= (int) ($byPriority[0]['status'] ?? 0) === 1 ? 'checked' : '' ?>> Kho nhập tay (ưu tiên 1)</label>
            <div class="source-grid"><select form="<?= e($sourceFormId) ?>" name="primary_provider" data-product-select="<?= e($primaryInputId) ?>"><option value=""><?= e($stockText('admin_stock_no_primary_api')) ?></option><?php foreach ($providerNames as $key => $name): ?><option value="<?= e($key) ?>" <?= ($byPriority[1]['provider_key'] ?? '') === $key ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select><select id="<?= e($primaryInputId) ?>" form="<?= e($sourceFormId) ?>" name="primary_code" data-selected="<?= e($byPriority[1]['remote_product_code'] ?? '') ?>"><option value=""><?= e($stockText('admin_stock_select_product')) ?></option></select><select form="<?= e($sourceFormId) ?>" name="backup_provider" data-product-select="<?= e($backupInputId) ?>"><option value=""><?= e($stockText('admin_stock_no_backup_api')) ?></option><?php foreach ($providerNames as $key => $name): ?><option value="<?= e($key) ?>" <?= ($byPriority[2]['provider_key'] ?? '') === $key ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select><select id="<?= e($backupInputId) ?>" form="<?= e($sourceFormId) ?>" name="backup_code" data-selected="<?= e($byPriority[2]['remote_product_code'] ?? '') ?>"><option value=""><?= e($stockText('admin_stock_select_product')) ?></option></select></div>
        </td>
        <td><button form="<?= e($sourceFormId) ?>" type="submit">Lưu nguồn</button></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($products === []): ?><tr><td colspan="3">Chưa có sản phẩm.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<script type="application/json" id="supplier-products-data"><?= json_encode($remoteProductsByProvider, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    var products = JSON.parse(document.getElementById('supplier-products-data').textContent || '{}');
    var emptyLabel = <?= json_encode($stockText('admin_stock_select_product'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var missingLabel = <?= json_encode($stockText('admin_stock_missing_product'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    function fill(providerSelect, keepSelection) {
        var productSelect = document.getElementById(providerSelect.dataset.productSelect);
        if (!productSelect) return;
        var selected = keepSelection ? productSelect.dataset.selected : '';
        productSelect.replaceChildren(new Option(emptyLabel, ''));
        (products[providerSelect.value] || []).forEach(function (product) {
            var currency = product.currency || 'USDT';
            var label = product.product_name + ' — ' + product.effective_stock + ' — ' + (product.unit_price || '—') + ' ' + currency;
            productSelect.add(new Option(label, product.remote_product_code, false, product.remote_product_code === selected));
        });
        if (selected && !Array.from(productSelect.options).some(function (option) { return option.value === selected; })) {
            productSelect.add(new Option(missingLabel + ': ' + selected, selected, true, true));
        }
        productSelect.disabled = providerSelect.value === '';
    }
    document.querySelectorAll('select[data-product-select]').forEach(function (providerSelect) {
        fill(providerSelect, true);
        providerSelect.addEventListener('change', function () {
            var productSelect = document.getElementById(providerSelect.dataset.productSelect);
            if (productSelect) productSelect.dataset.selected = '';
            fill(providerSelect, false);
        });
    });
}());
</script>
<div class="card">
    <h3>Nhập kho hàng loạt</h3>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="import">
        <label for="stock_product">Sản phẩm</label>
        <select id="stock_product" name="product_id" required <?= $manualProducts === [] ? 'disabled' : '' ?>>
            <?php foreach ($manualProducts as $product): ?><option value="<?= e($product['id']) ?>"><?= e($product['name']) ?></option><?php endforeach; ?>
            <?php if ($manualProducts === []): ?><option value="">Chưa có sản phẩm bật kho nhập tay</option><?php endif; ?>
        </select>
        <label for="stock_content">Nội dung kho</label>
        <textarea id="stock_content" name="content" rows="10" placeholder="Mỗi dòng là một key hoặc user|pass" required></textarea>
        <small class="help-text">Các dòng rỗng và nội dung đã tồn tại trong sản phẩm sẽ tự động bị bỏ qua.</small>
        <button type="submit" <?= $manualProducts === [] ? 'disabled' : '' ?>>Nhập kho</button>
    </form>
</div>

<?php if ($editing !== null): ?>
<div class="card">
    <h3>Sửa dòng kho #<?= e($editing['id']) ?> — <?= e($editing['product_name']) ?></h3>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="stock_id" value="<?= e($editing['id']) ?>">
        <textarea name="content" rows="4" required><?= e($editing['content']) ?></textarea>
        <div class="actions"><button type="submit">Lưu thay đổi</button><a class="button secondary" href="?page=stocks">Hủy sửa</a></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h3>Chi tiết kho</h3>
    <form method="get" class="filter-form">
        <input type="hidden" name="page" value="stocks">
        <select name="product_id"><option value="0">Tất cả sản phẩm</option><?php foreach ($products as $product): ?><option value="<?= e($product['id']) ?>" <?= $filterProductId === (int) $product['id'] ? 'selected' : '' ?>><?= e($product['name']) ?></option><?php endforeach; ?></select>
        <select name="status"><option value="available" <?= $filterStatus === 'available' ? 'selected' : '' ?>>Chưa bán</option><option value="sold" <?= $filterStatus === 'sold' ? 'selected' : '' ?>>Đã bán</option><option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Tất cả</option></select>
        <button type="submit">Lọc</button>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Sản phẩm</th><th>Nội dung</th><th>Trạng thái</th><th>Đơn hàng</th><th>Thao tác</th></tr></thead>
        <tbody><?php foreach ($stockRows as $stock): ?><tr>
            <td><?= e($stock['id']) ?></td><td><?= e($stock['product_name']) ?></td><td><code class="stock-content"><?= e($stock['content']) ?></code></td>
            <td><span class="badge <?= (int) $stock['is_sold'] === 1 ? 'muted' : 'success' ?>"><?= (int) $stock['is_sold'] === 1 ? 'Đã bán' : 'Chưa bán' ?></span></td>
            <td><?= e($stock['order_code'] ?? '-') ?></td>
            <td><?php if ((int) $stock['is_sold'] === 0): ?><div class="actions"><a class="button" href="?page=stocks&edit=<?= e($stock['id']) ?>">Sửa</a><form method="post" onsubmit="return confirm('Xóa dòng kho này?')"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="stock_id" value="<?= e($stock['id']) ?>"><button type="submit" class="danger">Xóa</button></form></div><?php else: ?>—<?php endif; ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
</div>
