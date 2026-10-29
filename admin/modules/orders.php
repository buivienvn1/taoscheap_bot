<?php

declare(strict_types=1);

$detailId = max(0, (int) ($_GET['detail'] ?? 0));
if ($detailId > 0) {
    $order = $db->fetch('SELECT o.*,u.telegram_id,u.username,u.first_name,u.last_name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?', [$detailId]);
    ?>
    <h1>Chi tiết đơn hàng</h1>
    <div class="card">
    <?php if($order===null):?><p class="error-message">Không tìm thấy đơn hàng.</p>
    <?php else:?>
    <div class="grid">
      <div><strong>Code</strong><br><?=e($order['order_code'])?></div><div><strong>Mã chuyển khoản</strong><br><?=e($order['payment_code']?:'-')?></div>
      <div><strong>Telegram ID</strong><br><?=e($order['telegram_id'])?></div><div><strong>Khách hàng</strong><br><?=e(trim(($order['first_name']??'').' '.($order['last_name']??'')))?> <?=e($order['username']?'@'.$order['username']:'')?></div>
      <div><strong>Sản phẩm</strong><br><?=e($order['product_name']?:'-')?></div><div><strong>Số lượng</strong><br><?=e($order['quantity'])?></div>
      <div><strong>Đơn giá</strong><br><?=e(Helpers::money($order['unit_price']))?></div><div><strong>Tổng tiền</strong><br><?=e(Helpers::money((float)$order['amount']+(float)$order['wallet_amount']))?></div>
      <div><strong>Trừ ví</strong><br><?=e(Helpers::money($order['wallet_amount']))?></div><div><strong>Chuyển khoản</strong><br><?=e(Helpers::money($order['amount']))?></div>
      <div><strong>Thanh toán</strong><br><?=e($order['payment_method'])?></div><div><strong>Trạng thái</strong><br><?=e($order['status'].' / '.($order['result_reason']?:'-'))?></div>
      <div><strong>Cộng ví/hoàn</strong><br><?=e(Helpers::money($order['wallet_credited']))?></div><div><strong>Hoàn tất</strong><br><?=e($order['completed_at']?:$order['created_at'])?></div>
    </div>
    <?php $deliveries=$db->fetchAll('SELECT content FROM order_deliveries WHERE order_id=? ORDER BY id',[$detailId]); if($deliveries===[]&&trim((string)$order['stock_content'])!=='')$deliveries=[['content'=>$order['stock_content']]];?>
    <h2>Nội dung đã giao</h2><?php if($deliveries===[]):?><p>Không có nội dung giao hàng.</p><?php endif;?><?php foreach($deliveries as $delivery):?><pre class="stock-content"><?=e($delivery['content'])?></pre><?php endforeach;?>
    <?php endif;?>
    <p><a class="button secondary" href="?page=orders">Quay lại danh sách</a></p></div>
    <?php return;
}

$pageNumber=max(1,(int)($_GET['p']??1));$pageSize=30;$where=[];$params=[];
$filters=['code'=>trim((string)($_GET['code']??'')),'payment_code'=>trim((string)($_GET['payment_code']??'')),'telegram_id'=>trim((string)($_GET['telegram_id']??'')),'payment_method'=>trim((string)($_GET['payment_method']??'')),'status'=>trim((string)($_GET['status']??'')),'date_from'=>trim((string)($_GET['date_from']??'')),'date_to'=>trim((string)($_GET['date_to']??''))];
if($filters['code']!==''){$where[]='o.order_code LIKE ?';$params[]='%'.$filters['code'].'%';}
if($filters['payment_code']!==''){$where[]='o.payment_code LIKE ?';$params[]='%'.$filters['payment_code'].'%';}
if($filters['telegram_id']!==''&&preg_match('/^-?\d+$/',$filters['telegram_id'])){$where[]='u.telegram_id=?';$params[]=$filters['telegram_id'];}
if(in_array($filters['payment_method'],['wallet','vietqr_direct','hybrid'],true)){$where[]='o.payment_method=?';$params[]=$filters['payment_method'];}
if(in_array($filters['status'],['pending','processing','completed','failed','cancelled'],true)){$where[]='o.status=?';$params[]=$filters['status'];}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_from'])){$where[]='o.created_at>=?';$params[]=$filters['date_from'].' 00:00:00';}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters['date_to'])){$where[]='o.created_at<?';$params[]=date('Y-m-d 00:00:00',strtotime($filters['date_to'].' +1 day'));}
$whereSql=$where===[]?'':' WHERE '.implode(' AND ',$where);
$total=(int)($db->fetch('SELECT COUNT(*) total FROM orders o JOIN users u ON u.id=o.user_id'.$whereSql,$params)['total']??0);$totalPages=max(1,(int)ceil($total/$pageSize));$pageNumber=min($pageNumber,$totalPages);
$rows=$db->fetchAll('SELECT o.*,u.telegram_id FROM orders o JOIN users u ON u.id=o.user_id'.$whereSql.' ORDER BY o.id DESC LIMIT '.$pageSize.' OFFSET '.(($pageNumber-1)*$pageSize),$params);
$queryBase=$_GET;unset($queryBase['p'],$queryBase['message']);
?>
<h1>Đơn hàng</h1><div class="card">
<form method="get" class="toolbar"><input type="hidden" name="page" value="orders"><input name="code" value="<?=e($filters['code'])?>" placeholder="Code OD"><input name="payment_code" value="<?=e($filters['payment_code'])?>" placeholder="Mã DONATE"><input name="telegram_id" value="<?=e($filters['telegram_id'])?>" placeholder="Telegram ID"><select name="payment_method"><option value="">Mọi thanh toán</option><option value="wallet" <?=$filters['payment_method']==='wallet'?'selected':''?>>Ví</option><option value="vietqr_direct" <?=$filters['payment_method']==='vietqr_direct'?'selected':''?>>Chuyển khoản</option><option value="hybrid" <?=$filters['payment_method']==='hybrid'?'selected':''?>>Ví + chuyển khoản</option></select><select name="status"><option value="">Mọi trạng thái</option><?php foreach(['pending','processing','completed','failed','cancelled'] as $status):?><option value="<?=e($status)?>" <?=$filters['status']===$status?'selected':''?>><?=e($status)?></option><?php endforeach;?></select><input type="date" name="date_from" value="<?=e($filters['date_from'])?>"><input type="date" name="date_to" value="<?=e($filters['date_to'])?>"><button type="submit">Lọc</button><a class="button secondary" href="?page=orders">Xóa lọc</a></form>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Mã chuyển khoản</th><th>Khách</th><th>Sản phẩm</th><th>SL</th><th>Tổng</th><th>Thanh toán</th><th>Kết quả</th><th>Thời gian</th><th></th></tr></thead><tbody>
<?php if($rows===[]):?><tr><td colspan="10">Không có dữ liệu phù hợp.</td></tr><?php endif;?><?php foreach($rows as $row):?><tr><td><?=e($row['order_code'])?></td><td><?=e($row['payment_code']?:'-')?></td><td><?=e($row['telegram_id'])?></td><td><?=e($row['product_name']??'')?></td><td><?=e($row['quantity'])?></td><td><?=e(Helpers::money((float)$row['amount']+(float)$row['wallet_amount']))?></td><td><?=e($row['payment_method'])?></td><td><?=e(($row['status']??'').' / '.($row['result_reason']??'-'))?></td><td><?=e($row['completed_at']?:$row['created_at'])?></td><td><a class="button secondary" href="?page=orders&detail=<?=e($row['id'])?>">Chi tiết</a></td></tr><?php endforeach;?></tbody></table></div>
<div class="toolbar"><?php if($pageNumber>1):?><a class="button secondary" href="?<?=e(http_build_query($queryBase+['page'=>'orders','p'=>$pageNumber-1]))?>">Trang trước</a><?php endif;?><span>Trang <?=e($pageNumber)?> / <?=e($totalPages)?> · <?=e($total)?> bản ghi</span><?php if($pageNumber<$totalPages):?><a class="button secondary" href="?<?=e(http_build_query($queryBase+['page'=>'orders','p'=>$pageNumber+1]))?>">Trang sau</a><?php endif;?></div>
</div>
