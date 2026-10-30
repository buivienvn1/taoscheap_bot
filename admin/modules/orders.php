<?php

declare(strict_types=1);

$adminLang = $adminLanguage ?? new Language('vi');
$orderText = static fn (string $key): string => $adminLang->get($key);

$formatCustomer = static function (array $order): string {
    $name = trim((string) ($order['first_name'] ?? '') . ' ' . (string) ($order['last_name'] ?? ''));
    $username = trim((string) ($order['username'] ?? ''));
    return trim($name . ($username !== '' ? ' @' . $username : '')) ?: '-';
};

$buildOrderDetail = static function (array $order, array $deliveries) use ($formatCustomer): array {
    return [
        'code' => (string) ($order['order_code'] ?? '-'),
        'payment_code' => (string) ($order['payment_code'] ?? '') ?: '-',
        'telegram_id' => (string) ($order['telegram_id'] ?? '-'),
        'customer' => $formatCustomer($order),
        'product' => (string) ($order['product_name'] ?? '') ?: '-',
        'quantity' => (string) ($order['quantity'] ?? '-'),
        'unit_price' => Helpers::money($order['unit_price'] ?? 0),
        'total' => Helpers::money((float) ($order['amount'] ?? 0) + (float) ($order['wallet_amount'] ?? 0)),
        'wallet_amount' => Helpers::money($order['wallet_amount'] ?? 0),
        'transfer_amount' => Helpers::money($order['amount'] ?? 0),
        'payment_method' => (string) ($order['payment_method'] ?? '-'),
        'status' => trim((string) ($order['status'] ?? '-') . ' / ' . ((string) ($order['result_reason'] ?? '') ?: '-')),
        'wallet_credited' => Helpers::money($order['wallet_credited'] ?? 0),
        'completed_at' => Helpers::dateTime((string) (($order['completed_at'] ?? '') ?: ($order['created_at'] ?? ''))),
        'deliveries' => array_values(array_map(static fn (array $delivery): string => (string) ($delivery['content'] ?? ''), $deliveries)),
    ];
};

$renderLegacyDetail = static function (array $order, array $deliveries) use ($adminLang, $formatCustomer): void {
    $fields = [
        ['admin_order_code', (string) ($order['order_code'] ?? '-')],
        ['admin_order_payment_code', (string) ($order['payment_code'] ?? '') ?: '-'],
        ['admin_order_telegram_id', (string) ($order['telegram_id'] ?? '-')],
        ['admin_order_customer', $formatCustomer($order)],
        ['admin_order_product', (string) ($order['product_name'] ?? '') ?: '-'],
        ['admin_order_quantity', (string) ($order['quantity'] ?? '-')],
        ['admin_order_unit_price', Helpers::money($order['unit_price'] ?? 0)],
        ['admin_order_total', Helpers::money((float) ($order['amount'] ?? 0) + (float) ($order['wallet_amount'] ?? 0))],
        ['admin_order_wallet_amount', Helpers::money($order['wallet_amount'] ?? 0)],
        ['admin_order_transfer_amount', Helpers::money($order['amount'] ?? 0)],
        ['admin_order_payment_method', (string) ($order['payment_method'] ?? '-')],
        ['admin_order_status', trim((string) ($order['status'] ?? '-') . ' / ' . ((string) ($order['result_reason'] ?? '') ?: '-'))],
        ['admin_order_wallet_credited', Helpers::money($order['wallet_credited'] ?? 0)],
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
$rows = $db->fetchAll('SELECT o.*,u.telegram_id,u.username,u.first_name,u.last_name FROM orders o JOIN users u ON u.id=o.user_id' . $whereSql . ' ORDER BY o.id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNumber - 1) * $pageSize), $params);

$orderDetails = [];
foreach ($rows as $row) {
    $deliveries = $db->fetchAll('SELECT content FROM order_deliveries WHERE order_id=? ORDER BY id', [(int) $row['id']]);
    if ($deliveries === [] && trim((string) ($row['stock_content'] ?? '')) !== '') {
        $deliveries = [['content' => $row['stock_content']]];
    }
    $orderDetails[(string) $row['id']] = $buildOrderDetail($row, $deliveries);
}
$queryBase = $_GET;
unset($queryBase['p'], $queryBase['message']);
?>
<h1><?= e($orderText('admin_orders_title')) ?></h1>
<div class="card">
<form method="get" class="toolbar"><input type="hidden" name="page" value="orders"><input name="code" value="<?= e($filters['code']) ?>" placeholder="Code OD"><input name="payment_code" value="<?= e($filters['payment_code']) ?>" placeholder="Mã DONATE"><input name="telegram_id" value="<?= e($filters['telegram_id']) ?>" placeholder="Telegram ID"><select name="payment_method"><option value="">Mọi thanh toán</option><option value="wallet" <?= $filters['payment_method'] === 'wallet' ? 'selected' : '' ?>>Ví</option><option value="vietqr_direct" <?= $filters['payment_method'] === 'vietqr_direct' ? 'selected' : '' ?>>Chuyển khoản</option><option value="hybrid" <?= $filters['payment_method'] === 'hybrid' ? 'selected' : '' ?>>Ví + chuyển khoản</option></select><select name="status"><option value="">Mọi trạng thái</option><?php foreach (['pending', 'processing', 'completed', 'failed', 'cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select><input type="date" name="date_from" value="<?= e($filters['date_from']) ?>"><input type="date" name="date_to" value="<?= e($filters['date_to']) ?>"><button type="submit">Lọc</button><a class="button secondary" href="?page=orders">Xóa lọc</a></form>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Mã chuyển khoản</th><th>Khách</th><th>Sản phẩm</th><th>SL</th><th>Tổng</th><th>Thanh toán</th><th>Kết quả</th><th>Thời gian</th><th></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="10">Không có dữ liệu phù hợp.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): $status = (string) ($row['status'] ?? 'pending'); ?><tr><td><?= e($row['order_code']) ?></td><td><?= e($row['payment_code'] ?: '-') ?></td><td><?= e($row['telegram_id']) ?></td><td><?= e($row['product_name'] ?? '') ?></td><td><?= e($row['quantity']) ?></td><td><?= e(Helpers::money((float) $row['amount'] + (float) $row['wallet_amount'])) ?></td><td><?= e($row['payment_method']) ?></td><td><span class="badge order-status <?= e($status) ?>"><?= e($status) ?></span><br><small><?= e($row['result_reason'] ?? '-') ?></small></td><td><?= e(Helpers::dateTime((string) ($row['completed_at'] ?: $row['created_at']))) ?></td><td><button type="button" class="secondary" data-open-order-modal="<?= e((string) $row['id']) ?>"><?= e($orderText('admin_order_detail_open')) ?></button></td></tr><?php endforeach; ?></tbody></table></div>
<div class="toolbar"><?php if ($pageNumber > 1): ?><a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'orders', 'p' => $pageNumber - 1])) ?>">Trang trước</a><?php endif; ?><span>Trang <?= e($pageNumber) ?> / <?= e($totalPages) ?> · <?= e($total) ?> bản ghi</span><?php if ($pageNumber < $totalPages): ?><a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'orders', 'p' => $pageNumber + 1])) ?>">Trang sau</a><?php endif; ?></div>
</div>

<div class="admin-modal stock-modal" id="order-modal" hidden aria-hidden="true">
    <div class="stock-modal-backdrop" data-close-order-modal></div>
    <div class="stock-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="order-modal-title">
        <div class="stock-modal-header"><h2 id="order-modal-title"><?= e($orderText('admin_order_detail_title')) ?></h2><button type="button" class="secondary" data-close-order-modal><?= e($orderText('admin_order_close')) ?></button></div>
        <div class="stock-modal-body" id="order-modal-body"></div>
    </div>
</div>
<script type="application/json" id="order-details-data"><?= json_encode($orderDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
    var modal = document.getElementById('order-modal');
    var body = document.getElementById('order-modal-body');
    var data = JSON.parse(document.getElementById('order-details-data').textContent || '{}');
    var labels = <?= json_encode([
        'code' => $orderText('admin_order_code'), 'payment_code' => $orderText('admin_order_payment_code'), 'telegram_id' => $orderText('admin_order_telegram_id'), 'customer' => $orderText('admin_order_customer'), 'product' => $orderText('admin_order_product'), 'quantity' => $orderText('admin_order_quantity'), 'unit_price' => $orderText('admin_order_unit_price'), 'total' => $orderText('admin_order_total'), 'wallet_amount' => $orderText('admin_order_wallet_amount'), 'transfer_amount' => $orderText('admin_order_transfer_amount'), 'payment_method' => $orderText('admin_order_payment_method'), 'status' => $orderText('admin_order_status'), 'wallet_credited' => $orderText('admin_order_wallet_credited'), 'completed_at' => $orderText('admin_order_completed_at'), 'delivered' => $orderText('admin_order_delivered_content'), 'no_delivery' => $orderText('admin_order_no_delivery'), 'copy' => $orderText('admin_order_copy'), 'copied' => $orderText('admin_order_copied')
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function closeModal() { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('modal-open'); }
    function openModal(id) {
        var item = data[id]; if (!item) return;
        var fields = ['code','payment_code','telegram_id','customer','product','quantity','unit_price','total','wallet_amount','transfer_amount','payment_method','status','wallet_credited','completed_at'];
        var html = '<div class="grid">';
        fields.forEach(function (key) { html += '<div><strong>' + labels[key] + '</strong><br>' + escapeHtml(item[key]) + '</div>'; });
        html += '</div><h3>' + labels.delivered + '</h3>';
        if (!item.deliveries.length) html += '<p>' + labels.no_delivery + '</p>';
        item.deliveries.forEach(function (content, index) { html += '<div class="copyable-content"><pre class="stock-content">' + escapeHtml(content) + '</pre><button type="button" class="secondary" data-copy-order-content data-copy-content="' + escapeAttr(content) + '">' + labels.copy + '</button></div>'; });
        body.innerHTML = html; modal.hidden = false; modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('modal-open');
        body.querySelectorAll('[data-copy-order-content]').forEach(function (button) { button.addEventListener('click', function () { copyText(button.dataset.copyContent, button); }); });
    }
    function escapeHtml(value) { return String(value == null ? '' : value).replace(/[&<>'"]/g, function (char) { return {'&':'&','<':'<','>':'>',"'":'&#039;','"':'"'}[char]; }); }
    function escapeAttr(value) { return escapeHtml(value).replace(/`/g, '&#096;'); }
    function copyText(value, button) { if (navigator.clipboard) { navigator.clipboard.writeText(value).then(function () { button.textContent = labels.copied; }); } else { var input = document.createElement('textarea'); input.value = value; document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove(); button.textContent = labels.copied; } }
    document.querySelectorAll('[data-open-order-modal]').forEach(function (button) { button.addEventListener('click', function () { openModal(button.dataset.openOrderModal); }); });
    document.querySelectorAll('[data-close-order-modal]').forEach(function (button) { button.addEventListener('click', closeModal); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) closeModal(); });
}());
</script>
