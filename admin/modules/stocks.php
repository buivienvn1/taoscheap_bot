<?php

$stockLanguage = $adminLanguage ?? new Language('vi');
$stockText = static fn (string $key, array $replace = []): string => $stockLanguage->get($key, $replace);
$isAjaxRequest = static function (): bool {
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
};
$respond = static function (bool $success, string $message, array $data = []) use ($isAjaxRequest): never {
    if ($isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    redirectAdmin('stocks', $message);
};
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

    if ($action === 'save_product') {
        $productId = max(0, (int) ($_POST['id'] ?? 0));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $features = trim((string) ($_POST['features'] ?? ''));
        $price = number_format(max(0, (float) ($_POST['price'] ?? 0)), 2, '.', '');
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $fakeSold = max(0, (int) ($_POST['fake_sold'] ?? 0));
        $status = isset($_POST['status']) ? 1 : 0;
        if ($name === '') {
            redirectAdmin('stocks', $stockText('admin_stock_product_name_invalid'));
        }
        $db->transaction(function (Database $db) use ($productId, $name, $description, $features, $price, $sortOrder, $fakeSold, $status): void {
            if ($productId > 0) {
                $product = $db->fetch('SELECT source_variant_id FROM products WHERE id=? FOR UPDATE', [$productId]);
                if ($product === null) return;
                $db->execute('UPDATE products SET name=?,description=?,features=?,sort_order=?,fake_sold=?,status=? WHERE id=?', [$name, $description, $features, $sortOrder, $fakeSold, $status, $productId]);
                if ($product['source_variant_id'] !== null) {
                    $db->execute('UPDATE variants SET name=?,price=?,status=?,sort_order=0 WHERE id=?', [$name, $price, $status, $product['source_variant_id']]);
                }
                return;
            }
            $db->execute('INSERT INTO products(name,description,features,sort_order,fake_sold,status) VALUES(?,?,?,?,?,?)', [$name, $description, $features, $sortOrder, $fakeSold, $status]);
            $newProductId = $db->lastInsertId();
            $db->execute("INSERT INTO variants(product_id,name,price,supplier_type,provider_code,sort_order,status) VALUES(?,?,?,'manual',NULL,0,?)", [$newProductId, $name, $price, $status]);
            $variantId = $db->lastInsertId();
            $db->execute('UPDATE products SET source_variant_id=? WHERE id=?', [$variantId, $newProductId]);
            $db->execute("INSERT INTO supplier_sources(variant_id,source_type,priority,status) VALUES(?,'manual',0,1)", [$variantId]);
        });
        redirectAdmin('stocks', $stockText('admin_stock_product_saved'));
    }

    if (($action === 'toggle_product' || $action === 'delete_product') && ($productId = max(0, (int) ($_POST['id'] ?? 0))) > 0) {
        if ($action === 'toggle_product') {
            $db->transaction(function (Database $db) use ($productId): void {
                $product = $db->fetch('SELECT status FROM products WHERE id=? FOR UPDATE', [$productId]);
                if ($product === null) return;
                $status = (int) $product['status'] === 1 ? 0 : 1;
                $db->execute('UPDATE products SET status=? WHERE id=?', [$status, $productId]);
                $db->execute('UPDATE variants SET status=? WHERE product_id=?', [$status, $productId]);
            });
            redirectAdmin('stocks', $stockText('admin_stock_product_saved'));
        }
        $db->transaction(function (Database $db) use ($productId): void {
            $product = $db->fetch('SELECT * FROM products WHERE id=? FOR UPDATE', [$productId]);
            if ($product === null) return;
            $reference = $db->fetch('SELECT 1 FROM variants v WHERE v.product_id=? AND (EXISTS (SELECT 1 FROM orders o WHERE o.variant_id=v.id) OR EXISTS (SELECT 1 FROM purchase_requests pr WHERE pr.variant_id=v.id)) LIMIT 1', [$productId]);
            if ($reference !== null) {
                $db->execute('UPDATE products SET status=0 WHERE id=?', [$productId]);
                $db->execute('UPDATE variants SET status=0 WHERE product_id=?', [$productId]);
                return;
            }
            $db->execute('DELETE s FROM stocks s JOIN variants v ON v.id=s.variant_id WHERE v.product_id=?', [$productId]);
            $db->execute('UPDATE products SET source_variant_id=NULL WHERE id=?', [$productId]);
            $db->execute('DELETE FROM variants WHERE product_id=?', [$productId]);
            $db->execute('DELETE FROM products WHERE id=?', [$productId]);
        });
        redirectAdmin('stocks', $stockText('admin_stock_product_saved'));
    }

    if ($action === 'refresh_inventory') {
        $result = $supplierCatalog->refreshInventory();
        $respond($result['success'], $stockText($result['success'] ? 'admin_stock_refresh_success' : 'admin_stock_refresh_partial'), $result);
    }

    if ($action === 'save_sources') {
        $productId = max(0, (int) ($_POST['product_id'] ?? 0));
        $product = $db->fetch('SELECT source_variant_id FROM products WHERE id=?', [$productId]);
        if ($product === null || $product['source_variant_id'] === null) $respond(false, 'Sản phẩm không hợp lệ.');
        $variantId = (int) $product['source_variant_id'];
        $providerNames = $supplierRegistry->names();
        $primaryProvider = trim((string) ($_POST['primary_provider'] ?? ''));
        $backupProvider = trim((string) ($_POST['backup_provider'] ?? ''));
        $primaryCode = trim((string) ($_POST['primary_code'] ?? ''));
        $backupCode = trim((string) ($_POST['backup_code'] ?? ''));
        if (($primaryProvider !== '' && !isset($providerNames[$primaryProvider]))
            || ($backupProvider !== '' && !isset($providerNames[$backupProvider]))) {
            $respond(false, 'Nhà cung cấp không hợp lệ.');
        }
        if ($primaryProvider !== '' && $primaryCode !== '' && $primaryProvider === $backupProvider && $primaryCode === $backupCode) {
            $respond(false, $stockText('admin_supplier_source_duplicate'));
        }
        foreach ([[$primaryProvider, $primaryCode], [$backupProvider, $backupCode]] as [$provider, $code]) {
            if ($provider === '' || $code === '') continue;
            $known = $db->fetch('SELECT 1 FROM supplier_products WHERE provider_key=? AND remote_product_code=? AND is_active=1', [$provider, $code]);
            if ($known === null && !isset($_POST['allow_manual_code'])) $respond(false, $stockText('admin_stock_code_not_synced'));
        }
        $expectedVersion = trim((string) ($_POST['source_version'] ?? ''));
        $currentSources = $db->fetchAll('SELECT source_type,provider_key,remote_product_code,priority,status FROM supplier_sources WHERE variant_id=? ORDER BY priority,id', [$variantId]);
        if (!hash_equals($sourceFingerprint($currentSources), $expectedVersion)) $respond(false, $stockText('admin_stock_source_conflict'));
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
        $respond(true, $stockText('admin_stock_source_save_success'));
    }

    if ($action === 'update') {
        $stockId = max(0, (int) ($_POST['stock_id'] ?? 0));
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($stockId === 0 || $content === '') {
            $respond(false, 'Nội dung kho không hợp lệ.');
        }
        $updated = $db->execute('UPDATE stocks SET content=? WHERE id=? AND is_sold=0', [$content, $stockId]);
        $respond($updated === 1, $updated === 1 ? 'Đã cập nhật dòng kho.' : 'Không thể sửa dòng kho đã bán hoặc không tồn tại.');
    }

    if ($action === 'delete') {
        $stockId = max(0, (int) ($_POST['stock_id'] ?? 0));
        $deleted = $db->execute('DELETE FROM stocks WHERE id=? AND is_sold=0', [$stockId]);
        $respond($deleted === 1, $deleted === 1 ? 'Đã xóa dòng kho.' : 'Không thể xóa dòng kho đã bán hoặc không tồn tại.');
    }

    if ($action === 'delete_available') {
        $productId = max(0, (int) ($_POST['product_id'] ?? 0));
        $deleted = $db->execute(
            'DELETE s FROM stocks s JOIN variants v ON v.id=s.variant_id WHERE v.product_id=? AND s.is_sold=0',
            [$productId]
        );
        $respond(true, 'Đã xóa ' . $deleted . ' dòng kho chưa bán.', ['deleted' => $deleted, 'product_id' => $productId]);
    }

    $productId = max(0, (int) ($_POST['product_id'] ?? 0));
    $product = $db->fetch(
        "SELECT p.id,p.name,p.source_variant_id FROM products p JOIN variants v ON v.id=p.source_variant_id WHERE p.id=? AND EXISTS(SELECT 1 FROM supplier_sources ss WHERE ss.variant_id=v.id AND ss.source_type='manual' AND ss.status=1)",
        [$productId]
    );
    if ($product === null) {
        $respond(false, 'Sản phẩm không dùng kho nhập tay.');
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
    $respond(true, $stockText('admin_stock_import_success', ['count' => $count, 'duplicate' => $duplicate, 'notified' => $notified]), ['count' => $count, 'duplicate' => $duplicate, 'notified' => $notified, 'product_id' => $productId]);
}

$products = $db->fetchAll(
    "SELECT p.id,p.name,p.description,p.features,p.fake_sold,p.status,p.sort_order,p.source_variant_id,v.price
     FROM products p JOIN variants v ON v.id=p.source_variant_id ORDER BY p.sort_order,p.id"
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
$remoteProductsByProvider = [];
$remoteProductsByKey = [];
foreach ($remoteProducts as $remoteProduct) {
    $providerKey = (string) $remoteProduct['provider_key'];
    $remoteCode = (string) $remoteProduct['remote_product_code'];
    $remoteProduct['effective_stock'] = !empty($remoteProduct['is_available'])
        ? max(0, (int) ($remoteProduct['stock'] ?? 0))
        : 0;
    $remoteProduct['display_price'] = $remoteProduct['unit_price'] === null
        ? '—'
        : Helpers::cleanNumber($remoteProduct['unit_price']);
    $remoteProductsByProvider[$providerKey][] = $remoteProduct;
    $remoteProductsByKey[$providerKey . "\0" . $remoteCode] = $remoteProduct;
}
$summary = $db->fetchAll(
    'SELECT p.id,p.name,p.description,p.features,p.status,p.sort_order,p.source_variant_id,p.fake_sold,v.price,COALESCE(sold.total,0) sold,
            (SELECT COUNT(*) FROM orders o2 JOIN variants ov ON ov.id=o2.variant_id WHERE ov.product_id=p.id) order_count
     FROM products p
     JOIN variants v ON v.id=p.source_variant_id
     LEFT JOIN (SELECT variant_id,COALESCE(SUM(quantity),0) total FROM orders WHERE status=\'completed\' GROUP BY variant_id) sold ON sold.variant_id=v.id
     ORDER BY p.sort_order,p.id'
);
$summaryStock = $supplierCatalog->variantStockMap(array_map(static fn (array $item): int => (int) $item['source_variant_id'], $summary));
foreach ($summary as &$summaryItem) {
    $stock = $summaryStock[(int) $summaryItem['source_variant_id']];
    $summaryItem['manual_available'] = (int) $stock['manual_available'];
    $summaryItem['api_available'] = (int) $stock['api_available'];
    $summaryItem['api_provider_name'] = $stock['api_provider_name'];
    $summaryItem['available'] = $summaryItem['manual_available'] + $summaryItem['api_available'];
}
unset($summaryItem);

$sourceModalProducts = [];
foreach ($products as $product) {
    $configured = $sourcesByVariant[(int) $product['source_variant_id']] ?? [];
    $byPriority = [];
    foreach ($configured as $source) {
        $byPriority[(int) $source['priority']] = $source;
    }
    $sourceModalProducts[(string) $product['id']] = [
        'id' => (int) $product['id'],
        'name' => (string) $product['name'],
        'version' => $sourceFingerprint($configured),
        'manual' => (int) ($byPriority[0]['status'] ?? 0) === 1,
        'primary_provider' => (string) ($byPriority[1]['provider_key'] ?? ''),
        'primary_code' => (string) ($byPriority[1]['remote_product_code'] ?? ''),
        'backup_provider' => (string) ($byPriority[2]['provider_key'] ?? ''),
        'backup_code' => (string) ($byPriority[2]['remote_product_code'] ?? ''),
    ];
}

$productModalData = [];
foreach ($summary as $item) {
    $productModalData[(string) $item['id']] = [
        'id' => (int) $item['id'],
        'name' => (string) $item['name'],
        'description' => (string) ($item['description'] ?? ''),
        'features' => (string) ($item['features'] ?? ''),
        'price' => (string) ($item['price'] ?? '0'),
        'sort_order' => (int) ($item['sort_order'] ?? 0),
        'fake_sold' => (int) ($item['fake_sold'] ?? 0),
        'status' => (int) ($item['status'] ?? 0) === 1,
    ];
}

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
<h1>Sản phẩm & Kho hàng</h1>
<nav class="stock-tabs" aria-label="Sản phẩm và kho hàng">
    <a href="#overview" data-stock-tab="overview"><?= e($stockText('admin_stock_tab_overview')) ?></a>
    <a href="#details" data-stock-tab="details"><?= e($stockText('admin_stock_tab_details')) ?></a>
</nav>
<section data-stock-panel="overview">
<div class="card">
    <div class="card-heading"><h3><?= e($stockText('admin_stock_overview')) ?></h3><div class="actions"><button type="button" data-open-stock-modal="product" data-product-id="0"><?= e($stockText('admin_stock_add_product')) ?></button><form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="refresh_inventory"><button type="submit" class="secondary"><?= e($stockText('admin_stock_refresh_inventory')) ?></button></form></div></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= e($stockText('admin_stock_product')) ?></th><th><?= e($stockText('admin_stock_product_price_column')) ?></th><th><?= e($stockText('admin_stock_product_stock_column')) ?></th><th><?= e($stockText('admin_stock_product_orders_column')) ?></th><th><?= e($stockText('admin_stock_product_actions')) ?></th></tr></thead>
        <tbody><?php foreach ($summary as $item): ?><tr data-product-row="<?= e($item['id']) ?>">
            <td><strong><?= e($item['name']) ?></strong><br><span class="badge <?= (int) $item['status'] === 1 ? 'success' : 'muted' ?>"><?= e($stockText((int) $item['status'] === 1 ? 'admin_stock_product_status_visible' : 'admin_stock_product_status_hidden')) ?></span></td>
            <td><?= e(Helpers::money($item['price'] ?? 0)) ?></td><td><strong><?= e($item['available']) ?></strong> <small>(<?= e($stockText('admin_stock_api_label')) ?>: <?= e($item['api_available']) ?>)</small><?php if ($item['api_provider_name'] !== null): ?><br><small><?= e($item['api_provider_name']) ?></small><?php endif; ?></td><td><?= e($item['order_count']) ?><br><small><?= e($stockText('admin_stock_sold_with_fake', ['sold_label' => $stockText('admin_stock_sold'), 'sold' => $item['sold'], 'fake_sold' => $item['fake_sold']])) ?></small></td>
            <td><div class="actions"><button type="button" data-open-stock-modal="product" data-product-id="<?= e($item['id']) ?>"><?= e($stockText('admin_stock_product_open_edit')) ?></button><button type="button" class="secondary" data-open-stock-modal="import" data-product-id="<?= e($item['id']) ?>"><?= e($stockText('admin_stock_btn_import')) ?></button><button type="button" class="secondary" data-open-stock-modal="source" data-product-id="<?= e($item['id']) ?>"><?= e($stockText('admin_stock_btn_config_api')) ?></button><form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="id" value="<?= e($item['id']) ?>"><button type="submit" class="secondary"><?= e($stockText('admin_stock_product_toggle')) ?></button></form><form method="post" onsubmit="return confirm('<?= e($stockText('admin_stock_product_delete_confirm')) ?>')"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?= e($item['id']) ?>"><button type="submit" class="danger"><?= e($stockText('admin_stock_product_delete')) ?></button></form></div></td>
        </tr><?php endforeach; ?><?php if ($summary === []): ?><tr><td colspan="5"><?= e($stockText('admin_stock_empty')) ?></td></tr><?php endif; ?></tbody>
    </table></div>
</div>
</section>
<?php if (false): ?>
<div class="card legacy-stock-config">
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
            var label = product.product_name + ' — ' + product.effective_stock + ' — ' + (product.display_price || '—') + ' ' + currency;
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
</div>
<?php endif; ?>
<?php if (false): ?>
<div class="card legacy-stock-import">
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
<?php endif; ?>

<section data-stock-panel="details">
<?php if ($editing !== null): ?>
<div class="card">
    <h3>Sửa dòng kho #<?= e($editing['id']) ?> — <?= e($editing['product_name']) ?></h3>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="stock_id" value="<?= e($editing['id']) ?>">
        <textarea name="content" rows="4" required><?= e($editing['content']) ?></textarea>
        <div class="actions"><button type="submit">Lưu thay đổi</button><a class="button secondary" href="?page=stocks#details">Hủy sửa</a></div>
    </form>
</div>
<?php endif; ?>
<div class="card">
    <h3><?= e($stockText('admin_stock_tab_details')) ?></h3>
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
</section>

<div class="stock-modal" id="stock-modal" hidden aria-hidden="true">
    <div class="stock-modal-backdrop" data-close-stock-modal></div>
    <div class="stock-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="stock-modal-title">
        <div class="stock-modal-header"><h2 id="stock-modal-title"></h2><button type="button" class="secondary" data-close-stock-modal><?= e($stockText('admin_stock_modal_close')) ?></button></div>
        <div class="stock-modal-body">
            <form method="post" id="stock-product-form">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" id="stock-product-id">
                <label for="stock-product-name"><?= e($stockText('admin_stock_product_name')) ?></label><input id="stock-product-name" name="name" required>
                <label for="stock-product-description"><?= e($stockText('admin_stock_product_description')) ?></label><textarea id="stock-product-description" name="description" rows="5"></textarea>
                <label for="stock-product-features"><?= e($stockText('admin_stock_product_features')) ?></label><textarea id="stock-product-features" name="features" rows="5"></textarea>
                <div class="form-grid"><div><label for="stock-product-price"><?= e($stockText('admin_stock_product_price')) ?></label><input id="stock-product-price" name="price" type="number" min="0" step="0.01" required></div><div><label for="stock-product-sort-order"><?= e($stockText('admin_stock_product_sort_order')) ?></label><input id="stock-product-sort-order" name="sort_order" type="number"></div><div><label for="stock-product-fake-sold"><?= e($stockText('admin_stock_product_fake_sold')) ?></label><input id="stock-product-fake-sold" name="fake_sold" type="number" min="0" step="1"></div></div>
                <label class="inline-check"><input type="checkbox" name="status" id="stock-product-status"> <?= e($stockText('admin_stock_product_visible')) ?></label>
                <div class="actions"><button type="submit"><?= e($stockText('admin_stock_product_save')) ?></button></div>
            </form>
            <form method="post" id="stock-import-form" data-ajax-form>
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="import"><input type="hidden" name="product_id" id="stock-import-product">
                <label for="stock-import-content"><?= e($stockText('admin_stock_import_content')) ?></label>
                <textarea id="stock-import-content" name="content" rows="10" placeholder="<?= e($stockText('admin_stock_import_placeholder')) ?>" required></textarea>
                <div class="actions"><button type="submit"><?= e($stockText('admin_stock_btn_import')) ?></button></div>
            </form>
            <form method="post" id="stock-source-form" data-ajax-form hidden>
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_sources"><input type="hidden" name="product_id" id="stock-source-product"><input type="hidden" name="source_version" id="stock-source-version">
                <label class="inline-check"><input type="checkbox" name="manual_enabled" id="stock-source-manual"> <?= e($stockText('admin_stock_manual_count')) ?></label>
                <div class="source-grid">
                    <select name="primary_provider" id="stock-primary-provider"><option value=""><?= e($stockText('admin_stock_no_primary_api')) ?></option><?php foreach ($providerNames as $key => $name): ?><option value="<?= e($key) ?>"><?= e($name) ?></option><?php endforeach; ?></select>
                    <select name="primary_code" id="stock-primary-code"><option value=""><?= e($stockText('admin_stock_select_product')) ?></option></select>
                    <select name="backup_provider" id="stock-backup-provider"><option value=""><?= e($stockText('admin_stock_no_backup_api')) ?></option><?php foreach ($providerNames as $key => $name): ?><option value="<?= e($key) ?>"><?= e($name) ?></option><?php endforeach; ?></select>
                    <select name="backup_code" id="stock-backup-code"><option value=""><?= e($stockText('admin_stock_select_product')) ?></option></select>
                </div>
                <div class="actions"><button type="submit"><?= e($stockText('admin_stock_modal_save')) ?></button></div>
            </form>
        </div>
    </div>
</div>
<div class="stock-toast" id="stock-toast" role="status" aria-live="polite" hidden></div>
<script type="application/json" id="product-data"><?= json_encode($productModalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="supplier-products-data"><?= json_encode($remoteProductsByProvider, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script type="application/json" id="stock-source-data"><?= json_encode($sourceModalProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    var modal = document.getElementById('stock-modal');
    var productForm = document.getElementById('stock-product-form');
    var importForm = document.getElementById('stock-import-form');
    var sourceForm = document.getElementById('stock-source-form');
    var productData = JSON.parse(document.getElementById('product-data').textContent || '{}');
    var sourceData = JSON.parse(document.getElementById('stock-source-data').textContent || '{}');
    var supplierProducts = JSON.parse(document.getElementById('supplier-products-data').textContent || '{}');
    var tabs = document.querySelectorAll('[data-stock-tab]');
    var panels = document.querySelectorAll('[data-stock-panel]');
    var title = document.getElementById('stock-modal-title');
    var toast = document.getElementById('stock-toast');
    var selectedProduct = null;
    var labels = {
        productCreateTitle: <?= json_encode($stockText('admin_stock_create_product'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        productEditTitle: <?= json_encode($stockText('admin_stock_edit_product'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        importTitle: <?= json_encode($stockText('admin_stock_modal_import_title'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        sourceTitle: <?= json_encode($stockText('admin_stock_modal_config_title'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        loading: <?= json_encode($stockText('admin_stock_loading'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        error: <?= json_encode($stockText('admin_stock_ajax_error'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
    };
    function setTab(name) {
        name = name === 'details' ? 'details' : 'overview';
        tabs.forEach(function (tab) { tab.classList.toggle('active', tab.dataset.stockTab === name); });
        panels.forEach(function (panel) { panel.hidden = panel.dataset.stockPanel !== name; });
        if (location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    }
    function notify(message, success) {
        toast.textContent = message; toast.className = 'stock-toast ' + (success ? 'success' : 'error'); toast.hidden = false;
        window.setTimeout(function () { toast.hidden = true; }, 4500);
    }
    function fillCode(providerId, codeId, selected) {
        var provider = document.getElementById(providerId), select = document.getElementById(codeId);
        select.replaceChildren(new Option(<?= json_encode($stockText('admin_stock_select_product'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, ''));
        (supplierProducts[provider.value] || []).forEach(function (product) {
            var label = product.product_name + ' — ' + product.effective_stock + ' — ' + (product.display_price || '—') + ' ' + (product.currency || 'USDT');
            select.add(new Option(label, product.remote_product_code, false, product.remote_product_code === selected));
        });
        if (selected && !Array.from(select.options).some(function (option) { return option.value === selected; })) select.add(new Option(selected, selected, true, true));
        select.disabled = provider.value === '';
    }
    function openModal(kind, id) {
        selectedProduct = sourceData[String(id)] || {id: id, name: '', version: '', manual: false, primary_provider: '', primary_code: '', backup_provider: '', backup_code: ''};
        var product = productData[String(id)] || {id: 0, name: '', description: '', features: '', price: '0', sort_order: 0, fake_sold: 0, status: true};
        productForm.hidden = kind !== 'product'; importForm.hidden = kind !== 'import'; sourceForm.hidden = kind !== 'source';
        if (kind === 'product') {
            document.getElementById('stock-product-id').value = product.id;
            document.getElementById('stock-product-name').value = product.name;
            document.getElementById('stock-product-description').value = product.description;
            document.getElementById('stock-product-features').value = product.features;
            document.getElementById('stock-product-price').value = product.price;
            document.getElementById('stock-product-sort-order').value = product.sort_order;
            document.getElementById('stock-product-fake-sold').value = product.fake_sold;
            document.getElementById('stock-product-status').checked = product.status;
            title.textContent = product.id ? labels.productEditTitle.replace(':product', product.name) : labels.productCreateTitle;
        } else if (kind === 'import') {
            document.getElementById('stock-import-product').value = id;
            title.textContent = labels.importTitle.replace(':product', selectedProduct.name);
        } else {
            document.getElementById('stock-source-product').value = id;
            document.getElementById('stock-source-version').value = selectedProduct.version;
            document.getElementById('stock-source-manual').checked = selectedProduct.manual;
            document.getElementById('stock-primary-provider').value = selectedProduct.primary_provider;
            document.getElementById('stock-backup-provider').value = selectedProduct.backup_provider;
            fillCode('stock-primary-provider', 'stock-primary-code', selectedProduct.primary_code);
            fillCode('stock-backup-provider', 'stock-backup-code', selectedProduct.backup_code);
            title.textContent = labels.sourceTitle.replace(':product', selectedProduct.name);
        }
        modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('modal-open');
    }
    function closeModal() { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('modal-open'); }
    function submitForm(form) {
        var submit = form.querySelector('button[type="submit"]'); submit.disabled = true; notify(labels.loading, true);
        fetch(window.location.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}, body: new FormData(form)})
            .then(function (response) { return response.json(); })
            .then(function (result) { if (!result.success) throw new Error(result.message || labels.error); notify(result.message, true); closeModal(); form.reset(); })
            .catch(function (error) { notify(error.message || labels.error, false); })
            .finally(function () { submit.disabled = false; });
    }
    tabs.forEach(function (tab) { tab.addEventListener('click', function (event) { event.preventDefault(); setTab(tab.dataset.stockTab); }); });
    document.querySelectorAll('[data-open-stock-modal]').forEach(function (button) { button.addEventListener('click', function () { openModal(button.dataset.openStockModal, button.dataset.productId); }); });
    document.querySelectorAll('[data-close-stock-modal]').forEach(function (button) { button.addEventListener('click', closeModal); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) closeModal(); });
    document.getElementById('stock-primary-provider').addEventListener('change', function () { fillCode('stock-primary-provider', 'stock-primary-code', ''); });
    document.getElementById('stock-backup-provider').addEventListener('change', function () { fillCode('stock-backup-provider', 'stock-backup-code', ''); });
    document.querySelectorAll('[data-ajax-form]').forEach(function (form) { form.addEventListener('submit', function (event) { event.preventDefault(); submitForm(form); }); });
    window.addEventListener('hashchange', function () { setTab(location.hash.slice(1)); });
    setTab(location.hash === '#details' ? 'details' : 'overview');
}());
</script>
