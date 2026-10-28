<?php
$stats = [
    'users' => $db->fetch('SELECT COUNT(*) total FROM users')['total'],
    'orders' => $db->fetch("SELECT COUNT(*) total FROM orders WHERE status = 'completed'")['total'],
    'revenue' => $db->fetch("SELECT COALESCE(SUM(amount),0) total FROM orders WHERE status = 'completed'")['total'],
    'stock' => $db->fetch('SELECT COUNT(*) total FROM stocks WHERE is_sold = 0')['total'],
];
?>
<h1>Tổng quan</h1>
<div class="grid">
  <div class="card"><div>Khách hàng</div><div class="stat"><?= e($stats['users']) ?></div></div>
  <div class="card"><div>Đơn hoàn thành</div><div class="stat"><?= e($stats['orders']) ?></div></div>
  <div class="card"><div>Doanh thu</div><div class="stat"><?= e(Helpers::money($stats['revenue'])) ?></div></div>
  <div class="card"><div>Kho còn lại</div><div class="stat"><?= e($stats['stock']) ?></div></div>
</div>
