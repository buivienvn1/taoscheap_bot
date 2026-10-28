<?php
$rows = $db->fetchAll('SELECT o.*,u.telegram_id FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 200');
?>
<h1>Đơn hàng</h1><div class="card"><table><thead><tr><th>Mã</th><th>Khách</th><th>Sản phẩm</th><th>SL</th><th>Đơn giá</th><th>Tổng</th><th>Thanh toán</th><th>Kết quả</th><th>Cộng ví</th><th>Thời gian</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?=e($row['order_code'])?></td><td><?=e($row['telegram_id'])?></td><td><?=e($row['product_name']??'')?></td><td><?=e($row['quantity'])?></td><td><?=e(Helpers::money($row['unit_price']??0))?></td><td><?=e(Helpers::money($row['amount']))?></td><td><?=e($row['payment_method'])?></td><td><?=e(($row['status']??'').' / '.($row['result_reason']??'-'))?></td><td><?=e(Helpers::money($row['wallet_credited']??0))?></td><td><?=e($row['created_at'])?></td></tr><?php endforeach; ?>
</tbody></table></div>
