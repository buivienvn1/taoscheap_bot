<?php
$rows = $db->fetchAll('SELECT o.*, u.telegram_id, v.name variant_name FROM orders o JOIN users u ON u.id=o.user_id JOIN variants v ON v.id=o.variant_id ORDER BY o.id DESC LIMIT 200');
?>
<h1>Đơn hàng</h1><div class="card"><table><thead><tr><th>Mã</th><th>Khách</th><th>Sản phẩm</th><th>Tiền</th><th>Trạng thái</th><th>Thời gian</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?=e($row['order_code'])?></td><td><?=e($row['telegram_id'])?></td><td><?=e($row['variant_name'])?></td><td><?=e(Helpers::money($row['amount']))?></td><td><?=e($row['status'])?></td><td><?=e($row['created_at'])?></td></tr><?php endforeach; ?>
</tbody></table></div>
