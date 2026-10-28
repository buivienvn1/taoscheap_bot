<?php
if($_SERVER['REQUEST_METHOD']==='POST'){
 verifyCsrf();$id=(int)($_POST['id']??0);$amount=(float)($_POST['amount']??0);
 if($amount>=(float)$app['min_deposit']) $db->transaction(function(Database $db)use($id,$amount){$d=$db->fetch("SELECT * FROM deposits WHERE id=? AND status='pending' FOR UPDATE",[$id]);if(!$d)return;$db->execute('UPDATE users SET balance=balance+? WHERE id=?',[$amount,$d['user_id']]);$db->execute("UPDATE deposits SET amount=?,status='completed',completed_at=NOW() WHERE id=?",[$amount,$id]);$db->execute("INSERT INTO transactions(user_id,type,amount,reference_type,reference_id,note)VALUES(?,'deposit',?,'deposit',?,?)",[$d['user_id'],$amount,$id,$d['code']]);});
 redirectAdmin('deposits','Đã duyệt nạp tiền.');
}
$rows=$db->fetchAll('SELECT d.*,u.telegram_id FROM deposits d JOIN users u ON u.id=d.user_id ORDER BY d.id DESC LIMIT 200');
?>
<h1>Nạp tiền</h1><div class="card"><table><thead><tr><th>Mã</th><th>Khách</th><th>Số tiền</th><th>Trạng thái</th><th>Duyệt tay</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><td><?=e($row['code'])?></td><td><?=e($row['telegram_id'])?></td><td><?=e(Helpers::money($row['amount']))?></td><td><?=e($row['status'])?></td><td><?php if($row['status']==='pending'):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrfToken())?>"><input type="hidden" name="id" value="<?=e($row['id'])?>"><input type="number" name="amount" min="<?=e($app['min_deposit'])?>" required><button>Duyệt</button></form><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div>
