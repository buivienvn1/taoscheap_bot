<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id = (int) ($_POST['id'] ?? 0);
    $amount = PaymentProcessor::normalizeAmount($_POST['amount'] ?? null);
    if ($id > 0 && $amount !== null && (float) $amount >= (float) $app['min_deposit']) {
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
$queryBase = $_GET;
unset($queryBase['p'], $queryBase['message']);
?>
<h1>Nạp tiền</h1>
<div class="card">
<form method="get" class="toolbar">
<input type="hidden" name="page" value="deposits">
<input name="reference_code" value="<?=e($filters['reference_code'])?>" placeholder="Code NAP">
<input name="transfer_code" value="<?=e($filters['transfer_code'])?>" placeholder="Mã DONATE">
<input name="telegram_id" value="<?=e($filters['telegram_id'])?>" placeholder="Telegram ID">
<select name="status"><option value="">Mọi trạng thái</option><?php foreach(['pending','completed','cancelled'] as $status):?><option value="<?=e($status)?>" <?=$filters['status']===$status?'selected':''?>><?=e($status)?></option><?php endforeach;?></select>
<input type="date" name="date_from" value="<?=e($filters['date_from'])?>"><input type="date" name="date_to" value="<?=e($filters['date_to'])?>">
<button type="submit">Lọc</button><a class="button secondary" href="?page=deposits">Xóa lọc</a>
</form>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Mã chuyển khoản</th><th>Khách</th><th>Số tiền</th><th>Số dư sau</th><th>Trạng thái</th><th>Thời gian</th><th>Duyệt tay</th></tr></thead><tbody>
<?php if($rows===[]):?><tr><td colspan="8">Không có dữ liệu phù hợp.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr><td><?=e($row['reference_code']?:'-')?></td><td><?=e($row['code'])?></td><td><?=e($row['telegram_id'])?></td><td><?=e(Helpers::money($row['amount']))?></td><td><?=e($row['balance_after']===null?'-':Helpers::money($row['balance_after']))?></td><td><?=e($row['status'])?></td><td><?=e($row['completed_at']?:$row['created_at'])?></td><td><?php if($row['status']==='pending'):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="id" value="<?=e($row['id'])?>"><input type="number" name="amount" min="<?=e($app['min_deposit'])?>" step="1" required><button>Duyệt</button></form><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div>
<div class="toolbar"><?php if($pageNumber>1):?><a class="button secondary" href="?<?=e(http_build_query($queryBase+['page'=>'deposits','p'=>$pageNumber-1]))?>">Trang trước</a><?php endif;?><span>Trang <?=e($pageNumber)?> / <?=e($totalPages)?> · <?=e($total)?> bản ghi</span><?php if($pageNumber<$totalPages):?><a class="button secondary" href="?<?=e(http_build_query($queryBase+['page'=>'deposits','p'=>$pageNumber+1]))?>">Trang sau</a><?php endif;?></div>
</div>
