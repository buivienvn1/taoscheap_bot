<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $amount = PaymentProcessor::normalizeAmount($_POST['amount'] ?? null);
    if ($id > 0 && $amount !== null) {
        $db->transaction(function (Database $db) use ($id, $amount, $app, $telegram): void {
            $deposit = $db->fetch("SELECT * FROM deposits WHERE id=? FOR UPDATE", [$id]);
            if ($deposit === null || $deposit['status'] !== 'pending') return;
            $user = $db->fetch('SELECT * FROM users WHERE id=? FOR UPDATE', [$deposit['user_id']]);
            if ($user === null) return;
            $referenceCode = trim((string) ($deposit['reference_code'] ?? ''));
            if ($referenceCode === '') $referenceCode = 'NAP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $balanceBefore = number_format((float) $user['balance'], 2, '.', '');
            $balanceAfter = number_format((float) $balanceBefore + (float) $amount, 2, '.', '');
            $completedAt = date('Y-m-d H:i:s');
            $db->execute('UPDATE users SET balance=? WHERE id=?', [$balanceAfter, $deposit['user_id']]);
            $db->execute(
                "UPDATE deposits SET reference_code=?,amount=?,balance_before=?,balance_after=?,status='completed',completed_at=? WHERE id=?",
                [$referenceCode, $amount, $balanceBefore, $balanceAfter, $completedAt, $id]
            );
            $db->execute(
                "INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,reference_type,reference_id,reference_code,note)
                 VALUES(?,'deposit',?,?,?,'deposit',?,?,?)",
                [$deposit['user_id'], $amount, $balanceBefore, $balanceAfter, $id, $referenceCode, 'manual_deposit_completed']
            );
            $outbox = new NotificationOutbox($db, $telegram);
            $params = [
                'amount' => Helpers::money($amount),
                'balance' => Helpers::money($balanceAfter),
                'code' => $referenceCode,
                'time' => Helpers::dateTime($completedAt),
                'cleanup_request_type' => 'deposit',
                'cleanup_request_id' => $id,
            ];
            $textLink = $db->fetch("SELECT * FROM payment_message_links WHERE request_type='deposit' AND request_id=? AND message_type='text' AND is_active=1 ORDER BY id DESC LIMIT 1", [$id]);
            $outbox->enqueueRecipient(
                (int) $user['id'], 'user', (int) $user['telegram_id'], (string) $user['language'],
                'deposit:' . $id . ':user', 'deposit_success_detailed', $params,
                $textLink === null ? 'send' : 'edit_or_send',
                $textLink === null ? null : (int) $textLink['chat_id'],
                $textLink === null ? null : (int) $textLink['message_id']
            );
            $customer = trim((string) $user['first_name'] . ' ' . (string) ($user['last_name'] ?? ''));
            if (trim((string) ($user['username'] ?? '')) !== '') $customer .= ' · @' . trim((string) $user['username']);
            foreach (Helpers::adminTelegramIds($app) as $adminId) {
                $outbox->enqueueAdmin($adminId, 'deposit:' . $id . ':admin:' . $adminId, 'admin_deposit_success', [
                    'customer' => $customer !== '' ? $customer : '-',
                    'telegram_id' => (string) $user['telegram_id'],
                    'amount' => Helpers::money($amount),
                    'balance' => Helpers::money($balanceAfter),
                    'payment_code' => (string) $deposit['code'],
                    'code' => $referenceCode,
                    'time' => Helpers::dateTime($completedAt),
                ]);
            }
        });
    }
    redirectAdmin('deposits', 'Đã xử lý yêu cầu duyệt nạp tiền.');
}

$tab = in_array((string) ($_GET['tab'] ?? 'deposits'), ['deposits', 'transactions'], true)
    ? (string) $_GET['tab']
    : 'deposits';
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$pageSize = 30;
$where = [];
$params = [];
$filters = [
    'reference_code' => trim((string) ($_GET['reference_code'] ?? '')),
    'transfer_code' => trim((string) ($_GET['transfer_code'] ?? '')),
    'telegram_id' => trim((string) ($_GET['telegram_id'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];
if ($filters['reference_code'] !== '') { $where[] = 'd.reference_code LIKE ?'; $params[] = '%' . $filters['reference_code'] . '%'; }
if ($filters['transfer_code'] !== '') { $where[] = 'd.code LIKE ?'; $params[] = '%' . $filters['transfer_code'] . '%'; }
if ($filters['telegram_id'] !== '' && preg_match('/^-?\d+$/', $filters['telegram_id'])) { $where[] = 'u.telegram_id=?'; $params[] = $filters['telegram_id']; }
if (in_array($filters['status'], ['pending', 'completed', 'cancelled'], true)) { $where[] = 'd.status=?'; $params[] = $filters['status']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) { $where[] = 'd.created_at>=?'; $params[] = $filters['date_from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) { $where[] = 'd.created_at<?'; $params[] = date('Y-m-d 00:00:00', strtotime($filters['date_to'] . ' +1 day')); }
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$total = (int) ($db->fetch('SELECT COUNT(*) total FROM deposits d JOIN users u ON u.id=d.user_id' . $whereSql, $params)['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $pageSize));
$pageNumber = min($pageNumber, $totalPages);
$rows = $db->fetchAll(
    'SELECT d.*,u.telegram_id FROM deposits d JOIN users u ON u.id=d.user_id' . $whereSql
    . ' ORDER BY d.id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNumber - 1) * $pageSize),
    $params
);
$transactionPage = max(1, (int) ($_GET['tx_p'] ?? 1));
$transactionFilters = [
    'customer' => trim((string) ($_GET['tx_customer'] ?? '')),
    'reference' => trim((string) ($_GET['tx_reference'] ?? '')),
    'type' => trim((string) ($_GET['tx_type'] ?? '')),
    'date_from' => trim((string) ($_GET['tx_date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['tx_date_to'] ?? '')),
];
$txWhere = [];
$txParams = [];
if ($transactionFilters['customer'] !== '') {
    $txWhere[] = '(u.telegram_id LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $term = '%' . ltrim($transactionFilters['customer'], '@') . '%';
    array_push($txParams, $term, $term, $term, $term);
}
if ($transactionFilters['reference'] !== '') { $txWhere[] = '(t.reference_code LIKE ? OR t.reference_id = ?)'; $txParams[] = '%' . $transactionFilters['reference'] . '%'; $txParams[] = ctype_digit($transactionFilters['reference']) ? (int) $transactionFilters['reference'] : 0; }
if (in_array($transactionFilters['type'], ['deposit', 'purchase', 'refund', 'referral_reward', 'adjustment'], true)) { $txWhere[] = 't.type=?'; $txParams[] = $transactionFilters['type']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionFilters['date_from'])) { $txWhere[] = 't.created_at>=?'; $txParams[] = $transactionFilters['date_from'] . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionFilters['date_to'])) { $txWhere[] = 't.created_at<?'; $txParams[] = date('Y-m-d 00:00:00', strtotime($transactionFilters['date_to'] . ' +1 day')); }
$txWhereSql = $txWhere === [] ? '' : ' WHERE ' . implode(' AND ', $txWhere);
$transactionTotal = (int) ($db->fetch('SELECT COUNT(*) total FROM transactions t JOIN users u ON u.id=t.user_id' . $txWhereSql, $txParams)['total'] ?? 0);
$transactionTotalPages = max(1, (int) ceil($transactionTotal / $pageSize));
$transactionPage = min($transactionPage, $transactionTotalPages);
$transactions = $db->fetchAll(
    'SELECT t.*,u.telegram_id,u.username,u.first_name,u.last_name FROM transactions t JOIN users u ON u.id=t.user_id' . $txWhereSql
    . ' ORDER BY t.id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($transactionPage - 1) * $pageSize),
    $txParams
);
$typeLabels = [
    'deposit' => $adminLang->get('admin_deposit_type_deposit'),
    'purchase' => $adminLang->get('admin_deposit_type_purchase'),
    'refund' => $adminLang->get('admin_deposit_type_refund'),
    'referral_reward' => $adminLang->get('admin_deposit_type_referral_reward'),
    'adjustment' => $adminLang->get('admin_deposit_type_adjustment'),
];
$queryBase = $_GET;
unset($queryBase['p'], $queryBase['tx_p'], $queryBase['message']);
?>
<h1><?= e($adminLang->get('admin_nav_deposits')) ?></h1>
<nav class="stock-tabs" aria-label="<?= e($adminLang->get('admin_nav_deposits')) ?>">
    <a href="#deposits" data-deposit-tab="deposits"><?= e($adminLang->get('admin_deposit_tab_requests')) ?></a>
    <a href="#transactions" data-deposit-tab="transactions"><?= e($adminLang->get('admin_deposit_tab_transactions')) ?></a>
</nav>
<section data-deposit-panel="deposits" class="card">
<form method="get" class="toolbar">
<input type="hidden" name="page" value="deposits"><input type="hidden" name="tab" value="deposits">
<input name="reference_code" value="<?= e($filters['reference_code']) ?>" placeholder="<?= e($adminLang->get('admin_deposit_reference_code')) ?>"><input name="transfer_code" value="<?= e($filters['transfer_code']) ?>" placeholder="<?= e($adminLang->get('admin_deposit_transfer_code')) ?>"><input name="telegram_id" value="<?= e($filters['telegram_id']) ?>" placeholder="<?= e($adminLang->get('admin_deposit_telegram_id')) ?>">
<select name="status"><option value=""><?= e($adminLang->get('admin_deposit_filter_all')) ?></option><?php foreach (['pending', 'completed', 'cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e($adminLang->get('admin_deposit_status_' . $status)) ?></option><?php endforeach; ?></select>
<input type="date" name="date_from" value="<?= e($filters['date_from']) ?>" aria-label="<?= e($adminLang->get('admin_deposit_filter_date_from')) ?>"><input type="date" name="date_to" value="<?= e($filters['date_to']) ?>" aria-label="<?= e($adminLang->get('admin_deposit_filter_date_to')) ?>"><button type="submit"><?= e($adminLang->get('admin_deposit_filter')) ?></button><a class="button secondary" href="?page=deposits#deposits"><?= e($adminLang->get('admin_deposit_clear_filter')) ?></a>
</form>
<div class="table-wrap"><table class="deposit-requests-table"><thead><tr><th><?= e($adminLang->get('admin_deposit_reference_code')) ?></th><th><?= e($adminLang->get('admin_deposit_transfer_code')) ?></th><th><?= e($adminLang->get('admin_deposit_customer')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_amount')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_balance_after')) ?></th><th><?= e($adminLang->get('admin_deposit_status')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_time')) ?></th><th><?= e($adminLang->get('admin_deposit_manual_approve')) ?></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="8"><?= e($adminLang->get('admin_deposit_no_requests')) ?></td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr><td><?= e($row['reference_code'] ?: '-') ?></td><td><?= e($row['code']) ?></td><td><?= e($row['telegram_id']) ?></td><td><?= e(Helpers::money($row['amount'])) ?></td><td><?= e($row['balance_after'] === null ? '-' : Helpers::money($row['balance_after'])) ?></td><td><span class="badge <?= e((string) $row['status']) ?>"><?= e($adminLang->get('admin_deposit_status_' . $row['status'])) ?></span></td><td><?= e(Helpers::dateTime((string) ($row['completed_at'] ?: $row['created_at']))) ?></td><td><?php if ($row['status'] === 'pending'): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><input type="number" name="amount" min="<?= e($app['min_deposit']) ?>" step="1" required><button><?= e($adminLang->get('admin_deposit_manual_approve')) ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="toolbar"><?php if ($pageNumber > 1): ?><a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'deposits', 'tab' => 'deposits', 'p' => $pageNumber - 1])) ?>#deposits"><?= e($adminLang->get('admin_deposit_previous_page')) ?></a><?php endif; ?><span><?= e($adminLang->get('admin_deposit_page', ['current' => (string) $pageNumber, 'total' => (string) $totalPages, 'count' => (string) $total])) ?></span><?php if ($pageNumber < $totalPages): ?><a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'deposits', 'tab' => 'deposits', 'p' => $pageNumber + 1])) ?>#deposits"><?= e($adminLang->get('admin_deposit_next_page')) ?></a><?php endif; ?></div>
</section>
<section data-deposit-panel="transactions" class="card">
<form method="get" class="toolbar"><input type="hidden" name="page" value="deposits"><input type="hidden" name="tab" value="transactions"><input name="tx_customer" value="<?= e($transactionFilters['customer']) ?>" placeholder="<?= e($adminLang->get('admin_deposit_filter_customer')) ?>"><input name="tx_reference" value="<?= e($transactionFilters['reference']) ?>" placeholder="<?= e($adminLang->get('admin_deposit_filter_reference')) ?>"><select name="tx_type"><option value=""><?= e($adminLang->get('admin_deposit_filter_all')) ?></option><?php foreach ($typeLabels as $type => $label): ?><option value="<?= e($type) ?>" <?= $transactionFilters['type'] === $type ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><input type="date" name="tx_date_from" value="<?= e($transactionFilters['date_from']) ?>"><input type="date" name="tx_date_to" value="<?= e($transactionFilters['date_to']) ?>"><button type="submit"><?= e($adminLang->get('admin_deposit_filter')) ?></button><a class="button secondary" href="?page=deposits#transactions"><?= e($adminLang->get('admin_deposit_clear_filter')) ?></a></form>
<div class="table-wrap"><table class="balance-history-table"><thead><tr><th><?= e($adminLang->get('admin_deposit_transaction_id')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_customer')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_type')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_amount')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_balance_before')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_balance_after')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_reference')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_note')) ?></th><th><?= e($adminLang->get('admin_deposit_transaction_time')) ?></th></tr></thead><tbody>
<?php if ($transactions === []): ?><tr><td colspan="9"><?= e($adminLang->get('admin_deposit_no_transactions')) ?></td></tr><?php endif; ?>
<?php foreach ($transactions as $transaction): ?><?php $customer = trim((string) $transaction['first_name'] . ' ' . (string) ($transaction['last_name'] ?? '')); $username = trim((string) ($transaction['username'] ?? '')); ?><tr><td><?= e($transaction['id']) ?></td><td><?= e($customer !== '' ? $customer : '-') ?><br><small><?= e((string) $transaction['telegram_id']) ?><?= $username !== '' ? ' · @' . e($username) : '' ?></small></td><td><span class="badge <?= e((string) $transaction['type']) ?>"><?= e($typeLabels[$transaction['type']] ?? $transaction['type']) ?></span></td><td class="<?= (float) $transaction['amount'] >= 0 ? 'positive' : 'negative' ?>"><?= (float) $transaction['amount'] >= 0 ? '+' : '' ?><?= e(Helpers::money($transaction['amount'])) ?></td><td><?= e($transaction['balance_before'] === null ? '-' : Helpers::money($transaction['balance_before'])) ?></td><td><?= e($transaction['balance_after'] === null ? '-' : Helpers::money($transaction['balance_after'])) ?></td><td><code><?= e((string) ($transaction['reference_code'] ?: $transaction['reference_id'] ?: '-')) ?></code></td><td><?= e((string) ($transaction['note'] ?: '-')) ?></td><td><?= e(Helpers::dateTime((string) $transaction['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="toolbar"><?php if ($transactionPage > 1): ?><a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'deposits', 'tab' => 'transactions', 'tx_p' => $transactionPage - 1])) ?>#transactions"><?= e($adminLang->get('admin_deposit_previous_page')) ?></a><?php endif; ?><span><?= e($adminLang->get('admin_deposit_page', ['current' => (string) $transactionPage, 'total' => (string) $transactionTotalPages, 'count' => (string) $transactionTotal])) ?></span><?php if ($transactionPage < $transactionTotalPages): ?><a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'deposits', 'tab' => 'transactions', 'tx_p' => $transactionPage + 1])) ?>#transactions"><?= e($adminLang->get('admin_deposit_next_page')) ?></a><?php endif; ?></div>
</section>
<script>
(function () {
    var panels = document.querySelectorAll('[data-deposit-panel]');
    var tabs = document.querySelectorAll('[data-deposit-tab]');
    function activate(tab) { panels.forEach(function (panel) { panel.hidden = panel.dataset.depositPanel !== tab; }); tabs.forEach(function (link) { link.classList.toggle('active', link.dataset.depositTab === tab); }); }
    function current() { return window.location.hash === '#transactions' ? 'transactions' : 'deposits'; }
    activate(current()); window.addEventListener('hashchange', function () { activate(current()); });
}());
</script>
