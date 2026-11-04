<?php

declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $name) use (&$failures): void {
    if (!$condition) {
        $failures[] = 'FAIL: ' . $name;
        return;
    }
    echo 'PASS: ' . $name . PHP_EOL;
};

$read = static fn (string $path): string => (string) file_get_contents(__DIR__ . '/../' . $path);
$users = $read('admin/modules/users.php');
$orders = $read('admin/modules/orders.php');
$warranty = $read('core/WarrantyService.php');
$migration = $read('migrations/021_add_warranty_orders.sql');
$outbox = $read('core/NotificationOutbox.php');
$referrals = $read('core/ReferralService.php');
$dashboard = $read('admin/modules/dashboard.php');
$profile = $read('bot/handlers/ProfileHandler.php');
$vi = require __DIR__ . '/../languages/vi.php';
$en = require __DIR__ . '/../languages/en.php';

$assert(str_contains($users, 'step="1"'), 'Balance adjustment accepts 19,900 VND');
$assert(str_contains($users, "preg_match('/^-?\\d+$/D'"), 'Balance adjustment enforces integer VND server-side');
$assert(str_contains($orders, "=== 'completed'") && str_contains($orders, "=== 'delivered'"), 'Warranty button requires completed and delivered order');
$assert(str_contains($orders, 'data-open-warranty-modal'), 'Admin order list exposes warranty action');
$assert(str_contains($orders, 'value="manual"') && str_contains($orders, 'value="stock"') && str_contains($orders, 'value="api"'), 'Warranty modal provides all three sources');
$assert(str_contains($orders, "document.getElementById('warranty-quantity').value = item.quantity"), 'Warranty quantity defaults to parent quantity');

$assert(str_contains($migration, 'parent_order_id') && str_contains($migration, 'FOREIGN KEY (parent_order_id)'), 'Migration stores parent-child warranty chain');
$assert(str_contains($warranty, "order_type='warranty'"), 'Warranty service creates and resolves warranty orders explicitly');
$assert(str_contains($warranty, "amount,wallet_amount,payment_method,status") && str_contains($warranty, "0,0,'wallet'"), 'Warranty child order is zero-value');
$assert(str_contains($warranty, "SELECT id,content FROM stocks") && str_contains($warranty, 'FOR UPDATE'), 'Internal stock allocation locks rows');
$assert(str_contains($warranty, "is_sold=1,order_id=?") && str_contains($warranty, 'stock_conflict'), 'Internal stock allocation detects conflicts');
$assert(
    str_contains($warranty, "'warranty-' . \$context['order_id'] . '-' . \$source['id']"),
    'API warranty uses stable idempotency key'
);
$assert(str_contains($warranty, "request_token=? AND order_type='warranty'"), 'Repeated submit reuses warranty order by request token');
$assert(
    str_contains($warranty, "'warranty:' . \$context['order_id'] . ':user'"),
    'Warranty notification uses unique outbox event key'
);
$assert(str_contains($outbox, "'warranty_delivery_success'"), 'Outbox renders warranty delivery items');

$assert(str_contains($dashboard, "order_type = 'purchase'"), 'Dashboard excludes warranty orders from purchase metrics');
$assert(str_contains($referrals, "order_type") && str_contains($referrals, "!== 'purchase'"), 'Referral service rejects warranty orders');
$assert(str_contains($profile, "order_type='purchase'"), 'Profile purchase count excludes warranty orders');
$assert(str_contains($profile, "'warranty_delivery_success'"), 'Telegram history renders warranty details separately');

$requiredTranslations = [
    'warranty_delivery_success',
    'history_warranty_summary',
    'admin_warranty_open',
    'admin_warranty_source_manual',
    'admin_warranty_source_stock',
    'admin_warranty_source_api',
    'admin_warranty_error_order_not_eligible',
];
foreach ($requiredTranslations as $key) {
    $assert(isset($vi[$key], $en[$key]) && $vi[$key] !== '' && $en[$key] !== '', 'VI/EN translation exists: ' . $key);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK: Warranty regression checks passed.\n";
