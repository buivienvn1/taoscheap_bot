<?php

declare(strict_types=1);

$language = $adminLanguage ?? new Language('vi');
$t = static fn (string $key, array $replace = []): string => $language->get($key, $replace);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'sync') {
        $providerKey = trim((string) ($_POST['provider_key'] ?? ''));
        $resource = (string) ($_POST['resource'] ?? '');
        $result = $resource === 'balance'
            ? $supplierCatalog->syncBalance($providerKey)
            : $supplierCatalog->syncProducts($providerKey);
        redirectAdmin('suppliers', $t($result['success'] ? 'admin_supplier_sync_success' : 'admin_supplier_sync_failed'));
    }
    if ($action === 'save_rates') {
        try {
            $supplierCatalog->saveExchangeRates(['USDT' => $_POST['rate_usdt'] ?? null]);
            redirectAdmin('suppliers', $t('admin_supplier_rates_saved'));
        } catch (Throwable) {
            redirectAdmin('suppliers', $t('admin_supplier_rate_invalid'));
        }
    }
    if ($action === 'refresh_coingecko_rate') {
        try {
            $supplierCatalog->refreshCoinGeckoRate();
            redirectAdmin('suppliers', $t('admin_supplier_coingecko_updated'));
        } catch (Throwable) {
            redirectAdmin('suppliers', $t('admin_supplier_coingecko_failed'));
        }
    }
    if ($action === 'assign_source') {
        $productId = max(0, (int) ($_POST['product_id'] ?? 0));
        $priority = (int) ($_POST['priority'] ?? 0);
        $providerKey = trim((string) ($_POST['provider_key'] ?? ''));
        $remoteCode = trim((string) ($_POST['remote_product_code'] ?? ''));
        if (!in_array($priority, [1, 2], true) || $supplierRegistry->get($providerKey) === null || $remoteCode === '') {
            redirectAdmin('suppliers', $t('admin_supplier_source_invalid'));
        }
        $remote = $db->fetch('SELECT 1 FROM supplier_products WHERE provider_key=? AND remote_product_code=? AND is_active=1', [$providerKey, $remoteCode]);
        $product = $db->fetch('SELECT source_variant_id FROM products WHERE id=?', [$productId]);
        if ($remote === null || $product === null || $product['source_variant_id'] === null) {
            redirectAdmin('suppliers', $t('admin_supplier_source_invalid'));
        }
        $variantId = (int) $product['source_variant_id'];
        $duplicate = $db->fetch('SELECT id FROM supplier_sources WHERE variant_id=? AND provider_key=? AND remote_product_code=? AND priority<>?', [$variantId, $providerKey, $remoteCode, $priority]);
        if ($duplicate !== null) {
            redirectAdmin('suppliers', $t('admin_supplier_source_duplicate'));
        }
        $db->execute(
            "INSERT INTO supplier_sources(variant_id,source_type,provider_key,remote_product_code,priority,status) VALUES(?,'api',?,?,?,1) ON DUPLICATE KEY UPDATE source_type='api',provider_key=VALUES(provider_key),remote_product_code=VALUES(remote_product_code),status=1",
            [$variantId, $providerKey, $remoteCode, $priority]
        );
        redirectAdmin('suppliers', $t('admin_supplier_source_saved'));
    }
}

$providerNames = $supplierRegistry->names();
$balances = $db->fetchAll('SELECT provider_key,currency,amount,fetched_at FROM supplier_balances ORDER BY provider_key,currency');
$balancesByProvider = [];
$usdtBalances = [];
foreach ($balances as $balance) {
    $balancesByProvider[(string) $balance['provider_key']][] = $balance;
    $currency = strtoupper((string) $balance['currency']);
    $providerKey = (string) $balance['provider_key'];
    if ($currency === 'USDT' || ($currency === 'USD' && !isset($usdtBalances[$providerKey]))) {
        $usdtBalances[$providerKey] = (float) $balance['amount'];
    }
}
$syncRows = $db->fetchAll('SELECT * FROM supplier_sync_state');
$syncByProvider = [];
foreach ($syncRows as $row) $syncByProvider[(string) $row['provider_key']][(string) $row['resource_type']] = $row;

$keyword = trim((string) ($_GET['q'] ?? ''));
$providerFilter = trim((string) ($_GET['provider'] ?? ''));
$inStockOnly = isset($_GET['in_stock']);
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$pageSize = 30;
$where = ['1=1'];
$params = [];
if ($keyword !== '') {
    $where[] = '(product_name LIKE ? OR remote_product_code LIKE ?)';
    $like = '%' . $keyword . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($providerFilter !== '' && isset($providerNames[$providerFilter])) {
    $where[] = 'provider_key=?';
    $params[] = $providerFilter;
}
if ($inStockOnly) $where[] = 'is_active=1 AND is_available=1 AND stock>0';
$countRow = $db->fetch('SELECT COUNT(*) total FROM supplier_products WHERE ' . implode(' AND ', $where), $params);
$total = (int) ($countRow['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $pageSize));
$pageNumber = min($pageNumber, $totalPages);
$offset = ($pageNumber - 1) * $pageSize;
$catalog = $db->fetchAll(
    'SELECT * FROM supplier_products WHERE ' . implode(' AND ', $where) . ' ORDER BY is_active DESC,is_available DESC,product_name,provider_key LIMIT ' . $pageSize . ' OFFSET ' . $offset,
    $params
);
$products = $db->fetchAll('SELECT id,name FROM products ORDER BY sort_order,id');
$rates = $supplierCatalog->exchangeRates();
$selected = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['compare'] ?? [])), static fn (int $id): bool => $id > 0)));
$currentCatalogIds = array_map(static fn (array $item): int => (int) $item['id'], $catalog);
$preservedSelected = array_values(array_diff($selected, $currentCatalogIds));
$paginationQuery = static function (int $targetPage) use ($keyword, $providerFilter, $inStockOnly, $selected): string {
    $query = [
        'page' => 'suppliers',
        'q' => $keyword,
        'provider' => $providerFilter,
        'p' => $targetPage,
    ];
    if ($inStockOnly) $query['in_stock'] = '1';
    if ($selected !== []) $query['compare'] = $selected;
    return '?' . http_build_query($query);
};
$comparison = [];
if ($selected !== []) {
    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    $comparison = $db->fetchAll('SELECT * FROM supplier_products WHERE id IN (' . $placeholders . ') ORDER BY price_vnd IS NULL,price_vnd', $selected);
}
?>
<h1><?= e($t('admin_suppliers_title')) ?></h1>
<p class="help-text"><?= e($t('admin_supplier_reference_notice')) ?></p>

<div class="supplier-card-grid">
<?php foreach ($supplierRegistry->all() as $providerKey => $provider): $productState = $syncByProvider[$providerKey]['products'] ?? null; $balanceState = $syncByProvider[$providerKey]['balance'] ?? null; ?>
    <section class="card supplier-status-card">
        <div class="card-heading"><h3><?= e($provider->name()) ?></h3><span class="badge <?= $provider->isConfigured() ? 'success' : 'muted' ?>"><?= e($t($provider->isConfigured() ? 'admin_supplier_configured' : 'admin_supplier_not_configured')) ?></span></div>
        <div class="supplier-balances">
            <?php foreach ($balancesByProvider[$providerKey] ?? [] as $balance): ?><strong><?= e(Helpers::formatDecimal($balance['amount'])) ?> <?= e($balance['currency']) ?></strong><?php endforeach; ?>
            <?php if (($balancesByProvider[$providerKey] ?? []) === []): ?><span class="help-text"><?= e($t('admin_supplier_no_balance')) ?></span><?php endif; ?>
        </div>
        <small><?= e($t('admin_supplier_balance_updated')) ?>: <?= e($balanceState['successful_at'] ?? '-') ?></small><br>
        <small><?= e($t('admin_supplier_catalog_updated')) ?>: <?= e($productState['successful_at'] ?? '-') ?></small>
        <?php if (($balanceState['status'] ?? '') === 'error' || ($productState['status'] ?? '') === 'error'): ?><p class="error-text"><?= e($balanceState['error_message'] ?? $productState['error_message'] ?? '') ?></p><?php endif; ?>
        <div class="actions supplier-card-actions">
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="provider_key" value="<?= e($providerKey) ?>"><input type="hidden" name="resource" value="balance"><button type="submit" <?= !$provider->isConfigured() ? 'disabled' : '' ?>><?= e($t('admin_supplier_refresh_balance')) ?></button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="provider_key" value="<?= e($providerKey) ?>"><input type="hidden" name="resource" value="products"><button class="secondary" type="submit" <?= !$provider->isConfigured() ? 'disabled' : '' ?>><?= e($t('admin_supplier_refresh_catalog')) ?></button></form>
        </div>
    </section>
<?php endforeach; ?>
</div>

<section class="card">
    <h3><?= e($t('admin_supplier_exchange_rates')) ?></h3>
    <div class="rate-actions">
        <form method="post" class="rate-form"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="save_rates">
            <label>USDT → VND<input type="number" min="0.00000001" step="0.00000001" name="rate_usdt" value="<?= e(isset($rates['USDT']) ? Helpers::cleanNumber($rates['USDT']) : '') ?>" required></label>
            <button type="submit"><?= e($t('admin_save')) ?></button>
        </form>
        <form method="post" class="actions"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="refresh_coingecko_rate"><button type="submit" class="secondary"><?= e($t('admin_supplier_fetch_coingecko')) ?></button></form>
    </div>
    <small class="help-text"><?= e($t('admin_supplier_currency_policy')) ?></small>
</section>

<section class="card">
    <h3><?= e($t('admin_supplier_catalog')) ?></h3>
    <form method="get" class="supplier-search"><input type="hidden" name="page" value="suppliers"><input name="q" value="<?= e($keyword) ?>" placeholder="<?= e($t('admin_supplier_search_placeholder')) ?>"><select name="provider"><option value=""><?= e($t('admin_supplier_all_providers')) ?></option><?php foreach ($providerNames as $key => $name): ?><option value="<?= e($key) ?>" <?= $providerFilter === $key ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select><label class="inline-check"><input type="checkbox" name="in_stock" value="1" <?= $inStockOnly ? 'checked' : '' ?>> <?= e($t('admin_supplier_in_stock_only')) ?></label><button type="submit"><?= e($t('admin_search')) ?></button></form>
    <form method="get"><input type="hidden" name="page" value="suppliers"><input type="hidden" name="q" value="<?= e($keyword) ?>"><input type="hidden" name="provider" value="<?= e($providerFilter) ?>"><?php if ($inStockOnly): ?><input type="hidden" name="in_stock" value="1"><?php endif; ?><?php foreach ($preservedSelected as $selectedId): ?><input type="hidden" name="compare[]" value="<?= e($selectedId) ?>"><?php endforeach; ?><div class="table-wrap"><table><thead><tr><th></th><th><?= e($t('admin_supplier_provider')) ?></th><th><?= e($t('admin_supplier_remote_product')) ?></th><th><?= e($t('admin_supplier_stock')) ?></th><th><?= e($t('admin_supplier_original_price')) ?></th><th><?= e($t('admin_supplier_vnd_price')) ?></th><th><?= e($t('admin_status')) ?></th></tr></thead><tbody>
    <?php foreach ($catalog as $item): $effectiveStock = $supplierCatalog->effectiveStock($item, $usdtBalances[(string) $item['provider_key']] ?? 0.0); ?><tr><td><input type="checkbox" name="compare[]" value="<?= e($item['id']) ?>" <?= in_array((int) $item['id'], $selected, true) ? 'checked' : '' ?>></td><td><?= e($providerNames[$item['provider_key']] ?? $item['provider_key']) ?></td><td><strong><?= e($item['product_name']) ?></strong><br><code><?= e($item['remote_product_code']) ?></code></td><td><?= e($effectiveStock) ?><br><small><?= e($t('admin_supplier_nominal_stock')) ?>: <?= e($item['stock']) ?></small></td><td><?= $item['unit_price'] === null ? '—' : e(Helpers::formatDecimal($item['unit_price']) . ' USDT') ?><br><small><?= e($item['price_kind']) ?></small></td><td><?= $item['price_vnd'] === null ? '—' : e(number_format((float) $item['price_vnd'], 0, ',', '.') . ' VND') ?></td><td><span class="badge <?= (int) $item['is_active'] === 1 && (int) $item['is_available'] === 1 ? 'success' : 'muted' ?>"><?= e($t((int) $item['is_active'] === 1 ? 'admin_supplier_active' : 'admin_supplier_removed')) ?></span><br><small><?= e($item['fetched_at']) ?></small></td></tr><?php endforeach; ?>
    <?php if ($catalog === []): ?><tr><td colspan="7"><?= e($t('admin_supplier_empty_catalog')) ?></td></tr><?php endif; ?>
    </tbody></table></div><div class="toolbar"><button type="submit"><?= e($t('admin_supplier_compare_selected')) ?></button><span><?= e($t('admin_pagination', ['current' => $pageNumber, 'total' => $totalPages])) ?></span><?php if ($pageNumber > 1): ?><a class="button secondary" href="<?= e($paginationQuery($pageNumber - 1)) ?>"><?= e($t('admin_previous')) ?></a><?php endif; ?><?php if ($pageNumber < $totalPages): ?><a class="button secondary" href="<?= e($paginationQuery($pageNumber + 1)) ?>"><?= e($t('admin_next')) ?></a><?php endif; ?></div></form>
</section>

<?php if ($comparison !== []): $cheapestId = null; foreach ($comparison as $candidate) { if ((int) $candidate['is_active'] === 1 && (int) $candidate['is_available'] === 1 && (int) $candidate['stock'] > 0 && $candidate['price_vnd'] !== null) { $cheapestId = (int) $candidate['id']; break; } } ?>
<section class="card"><h3><?= e($t('admin_supplier_comparison')) ?></h3><p class="help-text"><?= e($t('admin_supplier_compare_warning')) ?></p><div class="table-wrap"><table><thead><tr><th><?= e($t('admin_supplier_remote_product')) ?></th><th><?= e($t('admin_supplier_vnd_price')) ?></th><th><?= e($t('admin_supplier_assign_source')) ?></th></tr></thead><tbody>
<?php foreach ($comparison as $item): ?><tr><td><?= e($providerNames[$item['provider_key']] ?? $item['provider_key']) ?> — <?= e($item['product_name']) ?> <code><?= e($item['remote_product_code']) ?></code> <?php if ((int) $item['id'] === $cheapestId): ?><span class="badge success"><?= e($t('admin_supplier_cheapest')) ?></span><?php endif; ?></td><td><?= $item['price_vnd'] === null ? '—' : e(number_format((float) $item['price_vnd'], 0, ',', '.') . ' VND') ?></td><td><form method="post" class="assign-source-form"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="assign_source"><input type="hidden" name="provider_key" value="<?= e($item['provider_key']) ?>"><input type="hidden" name="remote_product_code" value="<?= e($item['remote_product_code']) ?>"><select name="product_id" required><option value=""><?= e($t('admin_supplier_select_shop_product')) ?></option><?php foreach ($products as $product): ?><option value="<?= e($product['id']) ?>"><?= e($product['name']) ?></option><?php endforeach; ?></select><select name="priority"><option value="1"><?= e($t('admin_supplier_primary')) ?></option><option value="2"><?= e($t('admin_supplier_backup')) ?></option></select><button type="submit"><?= e($t('admin_supplier_assign')) ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
