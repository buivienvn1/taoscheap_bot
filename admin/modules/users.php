<?php

declare(strict_types=1);

$adminLang = $adminLanguage ?? new Language('vi');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $amount = (float) ($_POST['amount'] ?? 0);
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($amount !== 0.0 && $userId > 0) {
        $db->transaction(function (Database $db) use ($amount, $userId): void {
            $db->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $userId]);
            $db->execute("INSERT INTO transactions(user_id,type,amount,note) VALUES(?,'adjustment',?,'Admin điều chỉnh')", [$userId, $amount]);
        });
    }
    redirectAdmin('users', $adminLang->get('admin_users_balance_updated'));
}

$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$pageSize = 25;
$search = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($search !== '') {
    $cleanSearch = ltrim($search, '@');
    $term = '%' . $cleanSearch . '%';
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR CAST(u.telegram_id AS CHAR) LIKE ?)';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

$total = (int) ($db->fetch('SELECT COUNT(*) total FROM users u' . $whereSql, $params)['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $pageSize));
$pageNumber = min($pageNumber, $totalPages);
$offset = ($pageNumber - 1) * $pageSize;

$rows = $db->fetchAll(
    'SELECT u.*,
            (SELECT COALESCE(SUM(amount + wallet_amount), 0) FROM orders WHERE user_id = u.id AND status = \'completed\') AS total_spent,
            (SELECT COUNT(*) FROM orders WHERE user_id = u.id AND status = \'completed\') AS completed_orders_count,
            (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS total_orders_count
     FROM users u' . $whereSql . ' ORDER BY u.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
    $params
);

$queryBase = $_GET;
unset($queryBase['p'], $queryBase['message']);
?>
<h1><?= e($adminLang->get('admin_users_title')) ?></h1>

<div class="card">
    <form method="get" class="toolbar">
        <input type="hidden" name="page" value="users">
        <input name="q" value="<?= e($search) ?>" placeholder="<?= e($adminLang->get('admin_users_search_placeholder')) ?>" style="min-width: 280px;">
        <button type="submit"><?= e($adminLang->get('admin_search')) ?></button>
        <?php if ($search !== ''): ?>
            <a class="button secondary" href="?page=users"><?= e($adminLang->get('admin_clear_filter')) ?></a>
        <?php endif; ?>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Telegram</th>
                    <th><?= e($adminLang->get('admin_full_name')) ?></th>
                    <th><?= e($adminLang->get('admin_user_info')) ?></th>
                    <th><?= e($adminLang->get('admin_balance')) ?></th>
                    <th><?= e($adminLang->get('admin_total_spent')) ?></th>
                    <th><?= e($adminLang->get('admin_total_orders')) ?></th>
                    <th><?= e($adminLang->get('admin_joined_at')) ?></th>
                    <th><?= e($adminLang->get('admin_adjust_balance')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $fullName = trim((string) $row['first_name'] . ' ' . (string) ($row['last_name'] ?? '')); ?>
                    <tr>
                        <td>
                            <b><?= e((string) $row['telegram_id']) ?></b><br>
                            <?= trim((string) ($row['username'] ?? '')) !== '' ? '@' . e($row['username']) : e($adminLang->get('admin_username_empty')) ?>
                        </td>
                        <td><?= e($fullName !== '' ? $fullName : '-') ?></td>
                        <td>
                            <span class="badge <?= !empty($row['is_premium']) ? 'success' : 'muted' ?>">
                                <?= e($adminLang->get(!empty($row['is_premium']) ? 'admin_account_premium' : 'admin_account_standard')) ?>
                            </span>
                            <br><small><?= e($row['language_code'] ?? '-') ?></small>
                        </td>
                        <td><b><?= e(Helpers::money($row['balance'])) ?></b></td>
                        <td><code><?= e(Helpers::money($row['total_spent'])) ?></code></td>
                        <td>
                            <b><?= e($adminLang->get('admin_orders_completed_count', ['completed' => (string) $row['completed_orders_count']])) ?></b><br>
                            <small class="muted"><?= e($adminLang->get('admin_orders_total_sub', ['total' => (string) $row['total_orders_count']])) ?></small>
                        </td>
                        <td><?= e(Helpers::dateTime((string) $row['created_at'])) ?></td>
                        <td>
                            <form method="post" class="actions">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
                                <input name="amount" type="number" step="1000" placeholder="<?= e($adminLang->get('admin_adjust_placeholder')) ?>" style="width: 110px;">
                                <button type="submit"><?= e($adminLang->get('admin_save')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8"><?= e($adminLang->get('admin_users_empty')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="toolbar">
        <?php if ($pageNumber > 1): ?>
            <a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'users', 'p' => $pageNumber - 1])) ?>"><?= e($adminLang->get('admin_previous')) ?></a>
        <?php endif; ?>
        <span><?= e($adminLang->get('admin_pagination', ['current' => (string) $pageNumber, 'total' => (string) $totalPages])) ?> <?= e($adminLang->get('admin_users_pagination_records', ['total' => (string) $total])) ?></span>
        <?php if ($pageNumber < $totalPages): ?>
            <a class="button secondary" href="?<?= e(http_build_query($queryBase + ['page' => 'users', 'p' => $pageNumber + 1])) ?>"><?= e($adminLang->get('admin_next')) ?></a>
        <?php endif; ?>
    </div>
</div>
