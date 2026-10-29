<?php
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $amount=(float)($_POST['amount']??0); $userId=(int)($_POST['user_id']??0);
    if($amount!==0.0) $db->transaction(function(Database $db)use($amount,$userId){$db->execute('UPDATE users SET balance=balance+? WHERE id=?',[$amount,$userId]);$db->execute("INSERT INTO transactions(user_id,type,amount,note) VALUES(?,'adjustment',?,'Admin điều chỉnh')",[$userId,$amount]);});
    redirectAdmin('users','Đã cập nhật số dư.');
}
$rows=$db->fetchAll('SELECT * FROM users ORDER BY id DESC LIMIT 200');
$adminLang=new Language('vi');
?>
<h1>Khách hàng & Ví</h1><div class="card"><div class="table-wrap"><table><thead><tr><th>Telegram</th><th><?=e($adminLang->get('admin_full_name'))?></th><th><?=e($adminLang->get('admin_user_info'))?></th><th>Số dư</th><th><?=e($adminLang->get('admin_joined_at'))?></th><th>Điều chỉnh</th></tr></thead><tbody>
<?php foreach($rows as $row): ?>
<?php $fullName=trim((string)$row['first_name'].' '.(string)($row['last_name']??'')); ?>
<tr><td><?=e($row['telegram_id'])?><br><?=trim((string)($row['username']??''))!==''?'@'.e($row['username']):e($adminLang->get('admin_username_empty'))?></td><td><?=e($fullName)?></td><td><span class="badge <?=!empty($row['is_premium'])?'success':'muted'?>"><?=e($adminLang->get(!empty($row['is_premium'])?'admin_account_premium':'admin_account_standard'))?></span><br><?=e($row['language_code']??'-')?></td><td><?=e(Helpers::money($row['balance']))?></td><td><?=e($row['created_at'])?></td><td><form method="post" class="actions"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="user_id" value="<?=e($row['id'])?>"><input name="amount" type="number" step="1000" placeholder="+ hoặc -"><button>Lưu</button></form></td></tr>
<?php endforeach; ?>
<?php if($rows===[]): ?><tr><td colspan="6"><?=e($adminLang->get('admin_users_empty'))?></td></tr><?php endif; ?>
</tbody></table></div></div>
