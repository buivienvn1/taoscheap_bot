<?php

declare(strict_types=1);

$adminLang = $adminLanguage ?? new Language('vi');
$orderText = static fn (string $key): string => $adminLang->get($key);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_warranty') {
    verifyCsrf();
    $result = $warrantyService->create(
        (int) ($_POST['parent_order_id'] ?? 0),
        (int) ($_POST['quantity'] ?? 0),
        trim((string) ($_POST['source_type'] ?? '')),
        (string) ($_POST['manual_content'] ?? ''),
        isset($_POST['supplier_source_id']) && $_POST['supplier_source_id'] !== ''
            ? (int) $_POST['supplier_source_id']
            : null,
        trim((string) ($_POST['request_token'] ?? ''))
    );
    $messageKey = ($result['success'] ?? false)
        ? 'admin_warranty_created'
        : (($result['pending'] ?? false) ? 'admin_warranty_pending' : 'admin_warranty_error_' . ($result['reason'] ?? 'invalid_request'));
    $message = $adminLang->get($messageKey, ['order' => (string) ($result['order_code'] ?? '-')]);
    if ($message === $messageKey) $message = $adminLang->get('admin_warranty_error_invalid_request');
    redirectAdmin('orders', $message);
}

$formatCustomer = static function (array $order): string {
    $name = trim((string) ($order['first_name'] ?? '') . ' ' . (string) ($order['last_name'] ?? ''));
    $username = trim((string) ($order['username'] ?? ''));
    return trim($name . ($username !== '' ? ' @' . $username : '')) ?: '-';
};

$buildOrderDetail = static function (array $order, array $deliveries, array $apiSources) use ($formatCustomer, $moneyFormatter): array {
    return [
        'code' => (string) ($order['order_code'] ?? '-'),
        'payment_code' => (string) ($order['payment_code'] ?? '') ?: '-',
        'telegram_id' => (string) ($order['telegram_id'] ?? '-'),
        'customer' => $formatCustomer($order),
        'product' => (string) ($order['product_name'] ?? '') ?: '-',
        'quantity' => (string) ($order['quantity'] ?? '-'),
        'unit_price' => $moneyFormatter->admin($order['unit_price'] ?? 0),
        'discount_amount' => $moneyFormatter->admin($order['discount_amount'] ?? 0),
        'total' => $moneyFormatter->admin((float) ($order['amount'] ?? 0) + (float) ($order['wallet_amount'] ?? 0)),
        'wallet_amount' => $moneyFormatter->admin($order['wallet_amount'] ?? 0),
        'transfer_amount' => $moneyFormatter->admin($order['amount'] ?? 0),
        'payment_method' => (string) ($order['payment_method'] ?? '-'),
        'status' => trim((string) ($order['status'] ?? '-') . ' / ' . ((string) ($order['result_reason'] ?? '') ?: '-')),
        'wallet_credited' => $moneyFormatter->admin($order['wallet_credited'] ?? 0),
        'completed_at' => Helpers::dateTime((string) (($order['completed_at'] ?? '') ?: ($order['created_at'] ?? ''))),
        'order_type' => (string) ($order['order_type'] ?? 'purchase'),
        'parent_order' => (string) ($order['parent_order_code'] ?? '') ?: '-',
        'warranty_source' => (string) ($order['warranty_source'] ?? '') ?: '-',
        'warranty_eligible' => ($order['status'] ?? '') === 'completed' && ($order['result_reason'] ?? '') === 'delivered',
        'api_sources' => $apiSources,
        'deliveries' => array_values(array_map(static fn (array $delivery): string => (string) ($delivery['content'] ?? ''), $deliveries)),
    ];
};

$renderLegacyDetail = static function (array $order, array $deliveries) use ($adminLang, $formatCustomer, $moneyFormatter): void {
    $fields = [
        ['admin_order_code', (string) ($order['order_code'] ?? '-')],
        ['admin_order_payment_code', (string) ($order['payment_code'] ?? '') ?: '-'],
        ['admin_order_telegram_id', (string) ($order['telegram_id'] ?? '-')],
        ['admin_order_customer', $formatCustomer($order)],
        ['admin_order_product', (string) ($order['product_name'] ?? '') ?: '-'],
        ['admin_order_quantity', (string) ($order['quantity'] ?? '-')],
        ['admin_order_unit_price', $moneyFormatter->admin($order['unit_price'] ?? 0)],
        ['admin_order_discount_amount', $moneyFormatter->admin($order['discount_amount'] ?? 0)],
        ['admin_order_total', $moneyFormatter->admin((float) ($order['amount'] ?? 0) + (float) ($order['wallet_amount'] ?? 0))],
        ['admin_order_wallet_amount', $moneyFormatter->admin($order['wallet_amount'] ?? 0)],
        ['admin_order_transfer_amount', $moneyFormatter->admin($order['amount'] ?? 0)],
        ['admin_order_payment_method', (string) ($order['payment_method'] ?? '-')],
        ['admin_order_status', trim((string) ($order['status'] ?? '-') . ' / ' . ((string) ($order['result_reason'] ?? '') ?: '-'))],
        ['admin_order_wallet_credited', $moneyFormatter->admin($order['wallet_credited'] ?? 0)],
        ['admin_order_completed_at', Helpers::dateTime((string) (($order['completed_at'] ?? '') ?: ($order['created_at'] ?? '')))],
    ];
    ?>
    <h1><?= e($adminLang->get('admin_order_detail_title')) ?></h1>
    <div class="card">
        <div class="grid">
            <?php foreach ($fields as [$label, $value]): ?>
                <div><strong><?= e($adminLang->get($label)) ?></strong><br><?= e($value) ?></div>
            <?php endforeach; ?>
        </div>
        <h2><?= e($adminLang->get('admin_order_delivered_content')) ?></h2>
        <?php if ($deliveries === []): ?><p><?= e($adminLang->get('admin_order_no_delivery')) ?></p><?php endif; ?>
        <?php foreach ($deliveries as $delivery): ?><pre class="stock-content"><?= e($delivery['content'] ?? '') ?></pre><?php endforeach; ?>
        <p><a class="button secondary" href="?page=orders"><?= e($adminLang->get('admin_order_back')) ?></a></p>
    </div>
    <?php
};

$detailId = max(0, (int) ($_GET['detail'] ?? 0));
if ($detailId > 0) {
    $order = $db->fetch('SELECT o.*,u.telegram_id,u.username,u.first_name,u.last_name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?', [$detailId]);
    if ($order === null) {
        echo '<h1>' . e($orderText('admin_order_detail_title')) . '</h1><div class="card"><p class="error-message">' . e($orderText('admin_order_not_found')) . '</p><p><a class="button secondary" href="?page=orders">' . e($orderText('admin_order_back')) . '</a></p></div>';
        return;
    }
    $deliveries = $db->fetchAll('SELECT content FROM order_deliveries WHERE order_id=? ORDER BY id', [$detailId]);
    if ($deliveries === [] && trim((string) ($order['stock_content'] ?? '')) !== '') {
        $deliveries = [['content' => $order['stock_content']]];
    }
    $renderLegacyDetail($order, $deliveries);
    return;
}

$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$pageSize = 30;
$where = [];
$params = [];
$filters = [
    'code' => trim((string) ($_GET['code'] ?? '')),
    'payment_code' => trim((string) ($_GET['payment_code'] ?? '')),
    'telegram_id' => trim((string) ($_GET['telegram_id'] ?? '')),
    'payment_method' => trim((string) ($_GET['payment_method'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];
if ($filters['code'] !== '') { $where[] = 'o.order_code LIKE ?'; $params[] = '%' . $filters['code'] . '%'; }
if ($filters['payment_code'] !== '') { $where[] = 'o.payment_code LIKE ?'; $params[] = '%' . $filters['payment_code'] . '%'; }
if ($filters['telegram_id'] !== '' && preg_match('/^-?\d+$/', $filters['telegram_id'])) { $where[] = 'u.telegram_id=?'; $params[] = $filters['telegram_id']; }
if (in_array($filters['payment_method'], ['wallet', 'vietqr_direct', 'hybrid'], true)) { $where[] = 'o.payment_method=?'; $params[] = $filters['payment_method']; }
if (in_array($filters['status'], ['pending', 'processing', 'completed', 'failed', 'cancelled'], true)) { $where[] = 'o.status=?'; $params[] = $filters['status']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) { $where[] = 'o.created_at>=?'; $params[] = $filters['date_from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) { $where[] = 'o.created_at<?'; $params[] = date('Y-m-d 00:00:00', strtotime($filters['date_to'] . ' +1 day')); }
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$total = (int) ($db->fetch('SELECT COUNT(*) total FROM orders o JOIN users u ON u.id=o.user_id' . $whereSql, $params)['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $pageSize));
$pageNumber = min($pageNumber, $totalPages);
$rows = $db->fetchAll(
    'SELECT o.*,parent.order_code parent_order_code,u.telegram_id,u.username,u.first_name,u.last_name
     FROM orders o
     JOIN users u ON u.id=o.user_id
     LEFT JOIN orders parent ON parent.id=o.parent_order_id'
    . $whereSql . ' ORDER BY o.id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNumber - 1) * $pageSize),
    $params
);

$orderDetails = [];
foreach ($rows as $row) {
    $deliveries = $db->fetchAll('SELECT content FROM order_deliveries WHERE order_id=? ORDER BY id', [(int) $row['id']]);
    if ($deliveries === [] && trim((string) ($row['stock_content'] ?? '')) !== '') {
        $deliveries = [['content' => $row['stock_content']]];
    }
    $orderDetails[(string) $row['id']] = $buildOrderDetail(
        $row,
        $deliveries,
        $warrantyService->apiSources((int) $row['variant_id'])
    );
}
$queryBase = $_GET;
unset($queryBase['p'], $queryBase['message']);
?>
<h1><?= e($orderText('admin_orders_title')) ?></h1>
<div class="card">
<form method="get" class="toolbar"><input type="hidden" name="page" value="orders"><input name="code" value="<?= e($filters['code']) ?>" placeholder="Code OD"><input name="payment_code" value="<?= e($filters['payment_code']) ?>" placeholder="Mã DONATE"><input name="telegram_id" value="<?= e($filters['telegram_id']) ?>" placeholder="Telegram ID"><select name="payment_method"><option value="">Mọi thanh toán</option><option value="wallet" <?= $filters['payment_method'] === 'wallet' ? 'selected' : '' ?>>Ví</option><option value="vietqr_direct" <?= $filters['payment_method'] === 'vietqr_direct' ? 'selected' : '' ?>>Chuyển khoản</option><option value="hybrid" <?= $filters['payment_method'] === 'hybrid' ? 'selected' : '' ?>>Ví + chuyển khoản</option></select><select name="status"><option value="">Mọi trạng thái</option><?php foreach (['pending', 'processing', 'completed', 'failed', 'cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select><input type="date" name="date_from" value="<?= e($filters['date_from']) ?>"><input type="date" name="date_to" value="<?= e($filters['date_to']) ?>"><button type="submit">Lọc</button><a class="button secondary" href="?page=orders">Xóa lọc</a></form>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Mã chuyển khoản</th><th>Khách</th><th>Sản phẩm</th><th>SL</th><th>Tổng</th><th>Thanh toán</th><th>Kết quả</th><th>Thời gian</th><th></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="10">Không có dữ liệu phù hợp.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): $status = (string) ($row['status'] ?? 'pending'); $fullName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')); $username = trim((string) ($row['username'] ?? '')); $warrantyEligible = $status === 'completed' && ($row['result_reason'] ?? '') === 'delivered'; ?><tr><td><?= e($row['order_code']) ?><?php if (($row['order_type'] ?? 'purchase') === 'warranty'): ?><br><span class="badge warranty-badge"><?= e($orderText('admin_order_type_warranty')) ?></span><?php if (($row['parent_order_code'] ?? '') !== ''): ?><br><small>↳ <?= e($row['parent_order_code']) ?></small><?php endif; ?><?php endif; ?></td><td><?= e($row['payment_code'] ?: '-') ?></td><td><?= e($fullName !== '' ? $fullName : '-') ?><br><small><?= $username !== '' ? '@' . e($username) . ' ∙ ' : '' ?><?= e((string) ($row['telegram_id'] ?? '-')) ?></small></td><td><?= e($row['product_name'] ?? '') ?></td><td><?= e($row['quantity']) ?></td><td><?= e($moneyFormatter->admin((float) $row['amount'] + (float) $row['wallet_amount'])) ?></td><td><?= e($row['payment_method']) ?></td><td><span class="badge order-status <?= e($status) ?>"><?= e($status) ?></span><br><small><?= e($row['result_reason'] ?? '-') ?></small></td><td><?= e(Helpers::dateTime((string) ($row['completed_at'] ?: $row['created_at']))) ?></td><td><div class="actions"><button type="button" class="secondary" data-open-order-modal="<?= e((string) $row['id']) ?>"><?= e($orderText('admin_order_detail_open')) ?></button><?php if ($warrantyEligible): ?><button type="button" data-open-warranty-modal="<?= e((string) $row['id']) ?>"><?= e($orderText('admin_warranty_open')) ?></button><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div>
<div class="toolbar"><?php if ($pageNumber > 1): ?><a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'orders', 'p' => $pageNumber - 1])) ?>">Trang trước</a><?php endif; ?><span>Trang <?= e($pageNumber) ?> / <?= e($totalPages) ?> · <?= e($total) ?> bản ghi</span><?php if ($pageNumber < $totalPages): ?><a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'orders', 'p' => $pageNumber + 1])) ?>">Trang sau</a><?php endif; ?></div>
</div>

<div class="admin-modal stock-modal" id="order-modal" hidden aria-hidden="true">
    <div class="stock-modal-backdrop" data-close-order-modal></div>
    <div class="stock-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="order-modal-title">
        <div class="stock-modal-header"><h2 id="order-modal-title"><?= e($orderText('admin_order_detail_title')) ?></h2><button type="button" class="secondary" data-close-order-modal><?= e($orderText('admin_order_close')) ?></button></div>
        <div class="stock-modal-body" id="order-modal-body"></div>
    </div>
</div>
<div class="admin-modal stock-modal" id="warranty-modal" hidden aria-hidden="true">
    <div class="stock-modal-backdrop" data-close-warranty-modal></div>
    <div class="stock-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="warranty-modal-title">
        <div class="stock-modal-header"><h2 id="warranty-modal-title"><?= e($orderText('admin_warranty_title')) ?></h2><button type="button" class="secondary" data-close-warranty-modal><?= e($orderText('admin_warranty_cancel')) ?></button></div>
        <div class="stock-modal-body">
            <form method="post" id="warranty-form">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="create_warranty">
                <input type="hidden" name="parent_order_id" id="warranty-parent-id">
                <input type="hidden" name="request_token" id="warranty-request-token">
                <p><strong><?= e($orderText('admin_warranty_order')) ?>:</strong> <span id="warranty-order-code">-</span></p>
                <label for="warranty-quantity"><?= e($orderText('admin_warranty_quantity')) ?></label>
                <input type="number" min="1" step="1" required name="quantity" id="warranty-quantity">
                <fieldset class="warranty-source-options">
                    <legend><?= e($orderText('admin_warranty_source')) ?></legend>
                    <label><input type="radio" name="source_type" value="manual" checked> <?= e($orderText('admin_warranty_source_manual')) ?></label>
                    <label><input type="radio" name="source_type" value="stock"> <?= e($orderText('admin_warranty_source_stock')) ?></label>
                    <label><input type="radio" name="source_type" value="api"> <?= e($orderText('admin_warranty_source_api')) ?></label>
                </fieldset>
                <div id="warranty-manual-group">
                    <label for="warranty-manual-content"><?= e($orderText('admin_warranty_manual_content')) ?></label>
                    <textarea name="manual_content" id="warranty-manual-content"></textarea>
                    <small class="help-text"><?= e($orderText('admin_warranty_manual_help')) ?></small>
                </div>
                <div id="warranty-api-group" hidden>
                    <label for="warranty-api-source"><?= e($orderText('admin_warranty_api_source')) ?></label>
                    <select name="supplier_source_id" id="warranty-api-source"></select>
                    <small class="help-text" id="warranty-api-empty" hidden><?= e($orderText('admin_warranty_api_empty')) ?></small>
                </div>
                <div class="actions"><button type="submit" id="warranty-submit"><?= e($orderText('admin_warranty_submit')) ?></button><button type="button" class="secondary" data-close-warranty-modal><?= e($orderText('admin_warranty_cancel')) ?></button></div>
            </form>
        </div>
    </div>
</div>
<script type="application/json" id="order-details-data"><?= json_encode($orderDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    var modal = document.getElementById('order-modal');
    var warrantyModal = document.getElementById('warranty-modal');
    var body = document.getElementById('order-modal-body');
    var data = JSON.parse(document.getElementById('order-details-data').textContent || '{}');
    var labels = <?= json_encode([
        'code' => $orderText('admin_order_code'), 'payment_code' => $orderText('admin_order_payment_code'), 'telegram_id' => $orderText('admin_order_telegram_id'), 'customer' => $orderText('admin_order_customer'), 'product' => $orderText('admin_order_product'), 'quantity' => $orderText('admin_order_quantity'), 'unit_price' => $orderText('admin_order_unit_price'), 'discount_amount' => $orderText('admin_order_discount_amount'), 'total' => $orderText('admin_order_total'), 'wallet_amount' => $orderText('admin_order_wallet_amount'), 'transfer_amount' => $orderText('admin_order_transfer_amount'), 'payment_method' => $orderText('admin_order_payment_method'), 'status' => $orderText('admin_order_status'), 'wallet_credited' => $orderText('admin_order_wallet_credited'), 'completed_at' => $orderText('admin_order_completed_at'), 'order_type' => $orderText('admin_order_type'), 'parent_order' => $orderText('admin_order_parent'), 'warranty_source' => $orderText('admin_order_warranty_source'), 'purchase' => $orderText('admin_order_type_purchase'), 'warranty' => $orderText('admin_order_type_warranty'), 'delivered' => $orderText('admin_order_delivered_content'), 'no_delivery' => $orderText('admin_order_no_delivery'), 'copy' => $orderText('admin_order_copy'), 'copied' => $orderText('admin_order_copied')
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function closeModal() { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('modal-open'); }
    function openModal(id) {
        var item = data[id]; if (!item) return;
        var fields = ['code','payment_code','telegram_id','customer','product','quantity','unit_price','discount_amount','total','wallet_amount','transfer_amount','payment_method','status','wallet_credited','completed_at','order_type'];
        if (item.order_type === 'warranty') fields.push('parent_order', 'warranty_source');
        var html = '<div class="grid">';
        fields.forEach(function (key) {
            var value = key === 'order_type' ? labels[item[key]] : item[key];
            html += '<div><strong>' + escapeHtml(labels[key]) + '</strong><br>' + escapeHtml(value) + '</div>';
        });
        html += '</div><h3>' + labels.delivered + '</h3>';
        if (!item.deliveries.length) html += '<p>' + labels.no_delivery + '</p>';
        item.deliveries.forEach(function (content, index) { html += '<div class="copyable-content"><pre class="stock-content">' + escapeHtml(content) + '</pre><button type="button" class="secondary" data-copy-order-content data-copy-content="' + escapeAttr(content) + '">' + labels.copy + '</button></div>'; });
        body.innerHTML = html; modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('modal-open');
        body.querySelectorAll('[data-copy-order-content]').forEach(function (button) { button.addEventListener('click', function () { copyText(button.dataset.copyContent, button); }); });
    }
    function escapeHtml(value) {
        var entities = {'&':'amp;','<':'lt;','>':'gt;',"'":'#039;','"':'quot;'};
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (char) {
            return '&' + entities[char];
        });
    }
    function escapeAttr(value) { return escapeHtml(value).replace(/`/g, '&#096;'); }
    function copyText(value, button) { if (navigator.clipboard) { navigator.clipboard.writeText(value).then(function () { button.textContent = labels.copied; }); } else { var input = document.createElement('textarea'); input.value = value; document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove(); button.textContent = labels.copied; } }
    function warrantyToken() {
        if (window.crypto && window.crypto.getRandomValues) {
            var bytes = new Uint32Array(4); window.crypto.getRandomValues(bytes);
            return 'warranty-' + Array.from(bytes).map(function (part) { return part.toString(16); }).join('');
        }
        return 'warranty-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }
    function selectedWarrantySource() {
        var selected = document.querySelector('input[name="source_type"]:checked');
        return selected ? selected.value : 'manual';
    }
    function updateWarrantyFields() {
        var source = selectedWarrantySource();
        var apiSelect = document.getElementById('warranty-api-source');
        document.getElementById('warranty-manual-group').hidden = source !== 'manual';
        document.getElementById('warranty-api-group').hidden = source !== 'api';
        document.getElementById('warranty-manual-content').required = source === 'manual';
        apiSelect.required = source === 'api';
        document.getElementById('warranty-submit').disabled = source === 'api' && !apiSelect.value;
    }
    function closeWarrantyModal() { warrantyModal.hidden = true; warrantyModal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('modal-open'); }
    function openWarrantyModal(id) {
        var item = data[id]; if (!item || !item.warranty_eligible) return;
        document.getElementById('warranty-parent-id').value = id;
        document.getElementById('warranty-order-code').textContent = item.code;
        document.getElementById('warranty-quantity').value = item.quantity;
        document.getElementById('warranty-request-token').value = warrantyToken();
        document.getElementById('warranty-manual-content').value = '';
        document.querySelector('input[name="source_type"][value="manual"]').checked = true;
        var apiSelect = document.getElementById('warranty-api-source');
        var apiSources = Array.isArray(item.api_sources) ? item.api_sources : [];
        apiSelect.innerHTML = '';
        apiSources.forEach(function (source) {
            var option = document.createElement('option'); option.value = source.id; option.textContent = source.label; apiSelect.appendChild(option);
        });
        document.getElementById('warranty-api-empty').hidden = apiSources.length !== 0;
        updateWarrantyFields();
        warrantyModal.hidden = false; warrantyModal.setAttribute('aria-hidden', 'false'); document.body.classList.add('modal-open');
        document.getElementById('warranty-quantity').focus();
    }
    document.querySelectorAll('[data-open-order-modal]').forEach(function (button) { button.addEventListener('click', function () { openModal(button.dataset.openOrderModal); }); });
    document.querySelectorAll('[data-close-order-modal]').forEach(function (button) { button.addEventListener('click', closeModal); });
    document.querySelectorAll('[data-open-warranty-modal]').forEach(function (button) { button.addEventListener('click', function () { openWarrantyModal(button.dataset.openWarrantyModal); }); });
    document.querySelectorAll('[data-close-warranty-modal]').forEach(function (button) { button.addEventListener('click', closeWarrantyModal); });
    document.querySelectorAll('input[name="source_type"]').forEach(function (input) { input.addEventListener('change', updateWarrantyFields); });
    document.getElementById('warranty-api-source').addEventListener('change', updateWarrantyFields);
    document.getElementById('warranty-form').addEventListener('submit', function () { document.getElementById('warranty-submit').disabled = true; });
    document.addEventListener('keydown', function (event) { if (event.key !== 'Escape') return; if (!warrantyModal.hidden) closeWarrantyModal(); else if (!modal.hidden) closeModal(); });
}());
</script>
