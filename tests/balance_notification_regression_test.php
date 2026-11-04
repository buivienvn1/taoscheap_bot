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
$orders = $read('bot/handlers/OrderHandler.php');
$profile = $read('bot/handlers/ProfileHandler.php');
$vi = require __DIR__ . '/../languages/vi.php';
$en = require __DIR__ . '/../languages/en.php';

$assert(
    str_contains($users, 'SELECT id,balance FROM users WHERE id=? FOR UPDATE'),
    'Admin adjustment locks the user balance row'
);
$assert(
    str_contains($users, "INSERT INTO transactions(user_id,type,amount,balance_before,balance_after,note) VALUES(?,'adjustment',?,?,?,?)"),
    'Admin adjustment stores balance before and after in transaction history'
);
$assert(
    str_contains($users, "'ADJ-' . str_pad((string) \$transactionId"),
    'Admin adjustment creates a visible transaction reference code'
);
$assert(
    str_contains($users, "'adjustment:' . \$transactionId . ':user'")
    && str_contains($users, "'balance_adjustment_notice'"),
    'Admin adjustment creates an idempotent user notification'
);
$assert(
    str_contains($orders, "'fulfillment_failed'")
    && str_contains($orders, "'refund'")
    && str_contains($orders, 'balance_before,balance_after'),
    'Failed wallet fulfillment remains recorded as a refund with balance history'
);
$assert(
    str_contains($orders, "'order:' . (int) \$order['id'] . ':wallet-refund:user'")
    && str_contains($orders, "'wallet_fulfillment_refund'"),
    'Failed wallet fulfillment creates an idempotent refund notification'
);
$assert(
    str_contains($orders, "'balance' => \$this->money->forUser(\$after"),
    'Wallet refund notification includes the resulting balance'
);
$assert(
    str_contains($profile, 'SELECT * FROM transactions WHERE user_id=?')
    && str_contains($profile, "transaction_type_' . \$row['type']"),
    'User wallet history exposes adjustment and refund transactions'
);

foreach (['balance_adjustment_notice', 'wallet_fulfillment_refund'] as $key) {
    $assert(
        isset($vi[$key], $en[$key]) && $vi[$key] !== '' && $en[$key] !== '',
        'VI/EN translation exists: ' . $key
    );
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK: Balance notification regression checks passed.\n";
