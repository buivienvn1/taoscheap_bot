<?php
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $amount=(float)($_POST['amount']??0); $userId=(int)($_POST['user_id']??0);
    if($amount!==0.0) $db->transaction(function(Database $db)use($amount,$userId){$db->execute('UPDATE users SET balance=balance+? WHERE id=?',[$amount,$userId]);$db->execute("INSERT INTO transactions(user_id,type,amount,note) VALUES(?,'adjustment',?,'Admin điều chỉnh')",[$userId,$amount]);});
    redirectAdmin('users','Đã cập nhật số dư.');
}
$rows=$db->fetchAll('SELECT * FROM users ORDER BY id DESC LIMIT 200');
?>
<h1>Khách hàng & Ví</h1><div class="card"><table><thead><tr><th>Telegram</th><th>Tên</th><th>Số dư</th><th>Điều chỉnh</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?=e($row['telegram_id'])?><br>@<?=e($row['username'])?></td><td><?=e($row['first_name'])?></td><td><?=e(Helpers::money($row['balance']))?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="user_id" value="<?=e($row['id'])?>"><input name="amount" type="number" step="1000" placeholder="+ hoặc -"><button>Lưu</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
