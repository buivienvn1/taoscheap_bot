<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

// Uses only a disposable database created by this test, never config/database.php.
spl_autoload_register(static function (string $class): void {
    foreach (['core', 'bot', 'bot/handlers', 'suppliers', 'suppliers/ApiProviders'] as $directory) {
        $file = __DIR__ . '/../' . $directory . '/' . $class . '.php';
        if (is_file($file)) { require_once $file; return; }
    }
});
date_default_timezone_set('Asia/Bangkok');
$port = (int) (getenv('REFERRAL_TEST_DB_PORT') ?: 13376);
$password = getenv('REFERRAL_TEST_DB_PASSWORD') ?: '';
$mockUrl = getenv('REFERRAL_TEST_TELEGRAM_URL') ?: '';
if (!preg_match('~^http://127\.0\.0\.1:\d+/$~D', $mockUrl)) {
    fwrite(STDERR, "Set REFERRAL_TEST_TELEGRAM_URL to the local fixture URL.\n");
    exit(1);
}
$dbConfig = ['host' => '127.0.0.1', 'port' => $port, 'name' => '', 'user' => 'root', 'password' => $password, 'charset' => 'utf8mb4'];
$makeTelegram = static function () use ($mockUrl): Telegram {
    $telegram = new Telegram('test-token');
    (new ReflectionProperty(Telegram::class, 'apiUrl'))->setValue($telegram, $mockUrl);
    return $telegram;
};

// Child processes verify real concurrent transactions against the same disposable DB.
if (($argv[1] ?? '') === 'pay') {
    $dbConfig['name'] = $argv[2];
    if (!preg_match('/^referral_test_[a-f0-9]+$/D', $dbConfig['name'])) exit(1);
    $service = new ReferralService(new Database($dbConfig), $makeTelegram());
    $service->pay((int) $argv[3]);
    exit;
}

$server = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$disposableDatabase = 'referral_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `' . $disposableDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dbConfig['name'] = $disposableDatabase;
$db = new Database($dbConfig);
$telegram = $makeTelegram();
$service = new ReferralService($db, $telegram);
$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $count++;
};
$statePath = getenv('REFERRAL_MOCK_STATE');
if (!$statePath) throw new RuntimeException('REFERRAL_MOCK_STATE is required.');
if (!is_dir(__DIR__ . '/../tmp/referral-tools')) mkdir(__DIR__ . '/../tmp/referral-tools', 0777, true);
$state = ['9999' => 'administrator'];
file_put_contents($statePath, json_encode($state));
$setStatus = static function (int $telegramId, string $status) use (&$state, $statePath): void {
    $state[(string) $telegramId] = $status;
    file_put_contents($statePath, json_encode($state));
};
$releaseLock = static function (int $userId) use ($db): void {
    $db->fetch('SELECT RELEASE_LOCK(?)', ['telegram_callback_user_' . $userId]);
};
try {
    $db->pdo()->exec(file_get_contents(__DIR__ . '/../install.sql'));
    $assert(!(bool) $service->config()['enabled'], 'fresh installation disabled');
    $assert((new MigrationRunner($db, __DIR__ . '/../migrations'))->status()['pending'] === [], 'fresh migration history complete');
    $db->execute('INSERT INTO users(telegram_id,balance) VALUES(10001,0.25)');
    $inviter = $db->lastInsertId();
    $code = $service->code($inviter);
    $assert((bool) preg_match('/^[A-Za-z0-9]{12}$/D', $code), '12-character referral code');
    $assert($code === $service->code($inviter), 'code stable');
    $config = ['enabled' => 1, 'reward' => 500, 'daily_limit' => 10, 'daily_budget' => 50000, 'channel_id' => '-100123456', 'channel_url' => 'https://t.me/test_channel', 'instructions' => ''];
    $service->saveConfig($config);
    $assert($service->config()['bot_username'] === 'referral_test_bot', 'bot username obtained during config validation');
    $registry = new SupplierRegistry([]);
    $stockFulfillment = new StockFulfillmentService($db, $registry);
    $catalog = new SupplierCatalogService($db, $registry);
    $app = ['bot_token' => 'test-token', 'admin_telegram_ids' => [], 'admin_username' => 'integration-admin'];
    $router = new Router($db, $telegram, new VietQR([]), $app, $catalog);
    $start = static function (int $id, string $payload) use ($router): void {
        $router->handle(['message' => ['chat' => ['id' => $id, 'type' => 'private'], 'from' => ['id' => $id, 'first_name' => '<new & friend>'], 'text' => '/start' . ($payload === '' ? '' : ' ' . $payload)]]);
    };
    $userId = static fn (int $id): int => (int) $db->fetch('SELECT id FROM users WHERE telegram_id=?', [$id])['id'];
    $callback = static function (int $id, string $data) use ($router, $releaseLock, $userId): void {
        $router->handle(['callback_query' => ['id' => 'callback-' . random_int(1, 9999999), 'from' => ['id' => $id], 'data' => $data, 'message' => ['chat' => ['id' => $id, 'type' => 'private'], 'message_id' => 100]]]);
        $releaseLock($userId($id));
    };
    $start(10002, '');
    $start(10002, $code);
    $assert($db->fetch('SELECT id FROM referrals WHERE invitee_id=?', [$userId(10002)]) === null, 'existing direct user never attributed');
    $start(10001, $code);
    $assert($db->fetch('SELECT id FROM referrals WHERE invitee_id=?', [$inviter]) === null, 'self referral rejected');
    $start(10003, 'BAD');
    $assert(!$service->needsGate($userId(10003)), 'invalid payload does not gate');
    $start(10004, $code);
    $friend = $userId(10004);
    $referral = (int) $db->fetch('SELECT id FROM referrals WHERE invitee_id=?', [$friend])['id'];
    $assert($service->needsGate($friend), 'new referred user gated');
    $start(10004, $code);
    $assert((int) $db->fetch('SELECT COUNT(*) n FROM referrals WHERE invitee_id=?', [$friend])['n'] === 1, 'repeated start does not duplicate referral');
    $otherCode = $service->code($userId(10002));
    $start(10004, $otherCode);
    $assert((int) $db->fetch('SELECT inviter_id FROM referrals WHERE id=?', [$referral])['inviter_id'] === $inviter, 'another link cannot replace inviter');
    $callback(10004, 'deposit');
    $assert($db->fetch('SELECT id FROM deposits WHERE user_id=?', [$friend]) === null, 'old callback cannot bypass gate');
    $callback(10004, 'lang:en');
    $assert($db->fetch('SELECT language FROM users WHERE id=?', [$friend])['language'] === 'en', 'language change allowed while gated');
    $setStatus(10004, 'left');
    $assert($service->checkGate($friend, 10004) === 'referral_not_member', 'gate rejects nonmember');
    $assert($service->checkGate($friend, 10004) === 'referral_check_wait', 'gate rate limit');
    $db->execute('UPDATE referrals SET last_gate_check_at=NULL WHERE id=?', [$referral]);
    $setStatus(10004, 'member');
    $callback(10004, 'referral_check');
    $assert(!$service->needsGate($friend), 'member unlocks shop');

    $db->execute("INSERT INTO products(name) VALUES('Test <product>')");
    $productId = $db->lastInsertId();
    $db->execute("INSERT INTO variants(product_id,name,price) VALUES(?,'Tiny order',1)", [$productId]);
    $variantId = $db->lastInsertId();
    $db->execute('UPDATE products SET source_variant_id=? WHERE id=?', [$variantId, $productId]);
    $db->execute("INSERT INTO supplier_sources(variant_id,source_type,priority) VALUES(?,'manual',1)", [$variantId]);
    for ($i = 0; $i < 30; $i++) $db->execute('INSERT INTO stocks(variant_id,content) VALUES(?,?)', [$variantId, 'test-delivery-' . $i]);
    $variant = $db->fetch('SELECT v.*,p.name product_name FROM variants v JOIN products p ON p.id=v.product_id WHERE v.id=?', [$variantId]);
    $orders = new OrderHandler($db, $telegram, new VietQR([]), $app, $stockFulfillment, $catalog, $service);
    $purchase = new ReflectionMethod(OrderHandler::class, 'purchaseWithWallet');
    $walletOrder = static function (int $buyer, string $token) use ($db, $purchase, $orders, $variant): int {
        $db->execute('UPDATE users SET balance=100 WHERE id=?', [$buyer]);
        $purchase->invoke($orders, $buyer, $variant, 1, '1.00', $token, new Language('vi'));
        return (int) $db->fetch('SELECT order_id FROM purchase_requests WHERE request_token=?', [$token])['order_id'];
    };
    $firstOrder = $walletOrder($friend, 'tiny-order');
    $assert($db->fetch('SELECT status FROM orders WHERE id=?', [$firstOrder])['status'] === 'completed', '1 VND order delivered');
    $assert($db->fetch('SELECT status FROM referrals WHERE id=?', [$referral])['status'] === 'queued', 'tiny purchase qualifies without threshold');
    $assert($service->pay($referral), 'first reward paid');
    $assert(!$service->pay($referral), 'duplicate payout rejected');
    $assert($db->fetch('SELECT balance FROM users WHERE id=?', [$inviter])['balance'] === '500.25', 'exact balance including existing cents');
    $walletOrder($friend, 'second-order');
    $assert((int) $db->fetch("SELECT COUNT(*) n FROM transactions WHERE type='referral_reward'")['n'] === 1, 'second purchase does not reward again');
    $assert((int) $db->fetch("SELECT COUNT(*) n FROM notification_outbox WHERE message_key='referral_reward_received'")['n'] === 1, 'reward notification exactly once');

    $start(10005, $code);
    $leftUser = $userId(10005);
    $setStatus(10005, 'left');
    $leftOrder = $walletOrder($leftUser, 'left-order');
    $assert($db->fetch('SELECT result FROM referral_order_checks WHERE order_id=?', [$leftOrder])['result'] === 'not_member', 'membership at completion enforced');
    $setStatus(10005, 'member');
    $assert($service->payBatch() === 0, 'rejoining does not reward old order');
    $walletOrder($leftUser, 'joined-new-order');
    $assert($service->payBatch() === 1, 'later qualifying purchase rewards');

    $start(10006, $code);
    $errorUser = $userId(10006);
    $setStatus(10006, 'api_error');
    $errorOrder = $walletOrder($errorUser, 'api-error-order');
    $assert($db->fetch('SELECT status FROM orders WHERE id=?', [$errorOrder])['status'] === 'completed', 'Telegram failure never blocks delivery');
    $check = $db->fetch('SELECT * FROM referral_order_checks WHERE order_id=?', [$errorOrder]);
    $assert($check['result'] === 'api_error', 'unavailable membership saved');
    $assert(!$service->approveException((int) $check['id'], '', 'admin'), 'manual approval requires reason');
    $assert($service->approveException((int) $check['id'], 'Verified channel records at completion', 'integration-admin'), 'manual exception approved');
    $assert(!$service->approveException((int) $check['id'], 'duplicate', 'admin'), 'manual exception idempotent');

    $outbox = new NotificationOutbox($db, $telegram);
    $processor = new PaymentProcessor($db, $outbox, $app, $stockFulfillment, $service);
    $start(10014, $code);
    $depositUser = $userId(10014);
    $depositCode = (new PaymentCodeRegistry($db))->reserve('deposit');
    $db->execute('INSERT INTO deposits(user_id,code) VALUES(?,?)', [$depositUser, $depositCode]);
    $depositId = $db->lastInsertId();
    (new PaymentCodeRegistry($db))->bind($depositCode, 'deposit', $depositId);
    $processor->process(['amount' => '2000', 'direction' => 'IN', 'content' => $depositCode, 'provider' => 'test', 'provider_event_id' => $depositCode]);
    $assert($db->fetch('SELECT status FROM deposits WHERE id=?', [$depositId])['status'] === 'completed', 'wallet deposit processed');
    $assert($db->fetch('SELECT status FROM referrals WHERE invitee_id=?', [$depositUser])['status'] === 'waiting', 'deposit does not qualify referral');
    $db->execute("INSERT INTO orders(order_code,user_id,variant_id,quantity,unit_price,amount,wallet_amount,payment_method,status) VALUES(?,?,?,1,2,1,1,'hybrid','pending')", [Helpers::orderCode(), $depositUser, $variantId]);
    $cancelledOrder = $db->lastInsertId();
    $orders->cancelOrder(10014, 100, $depositUser, $cancelledOrder, new Language('vi'));
    $assert($db->fetch('SELECT status FROM orders WHERE id=?', [$cancelledOrder])['status'] === 'cancelled', 'hybrid cancellation processed');
    $assert($db->fetch('SELECT id FROM referral_order_checks WHERE order_id=?', [$cancelledOrder]) === null, 'cancelled order never qualifies');
    $direct = static function (int $telegramId, string $method, string $amount, string $wallet = '0.00') use ($start, $code, $userId, $db, $variantId, $processor): array {
        $start($telegramId, $code);
        $buyer = $userId($telegramId);
        $orderCode = Helpers::orderCode();
        $db->execute("INSERT INTO orders(order_code,user_id,variant_id,quantity,unit_price,amount,wallet_amount,payment_method,status) VALUES(?,?,?,1,1,?,?,?,'pending')", [$orderCode, $buyer, $variantId, $amount, $wallet, $method]);
        $order = $db->lastInsertId();
        $paymentCode = (new PaymentCodeRegistry($db))->reserve('order', $order);
        $payment = ['amount' => $amount, 'direction' => 'IN', 'content' => $paymentCode, 'provider' => 'test', 'provider_event_id' => $paymentCode];
        $result = $processor->process($payment);
        return [$order, $buyer, $payment, $result];
    };
    [$qrOrder, $qrBuyer, $payment] = $direct(10007, 'vietqr_direct', '1.00');
    $assert($db->fetch('SELECT result FROM referral_order_checks WHERE order_id=?', [$qrOrder])['result'] === 'member', 'direct QR qualifies');
    $assert($processor->process($payment)['result'] === 'duplicate_code', 'QR replay harmless');
    [$hybridOrder] = $direct(10008, 'hybrid', '1.00', '1.00');
    $assert($db->fetch('SELECT result FROM referral_order_checks WHERE order_id=?', [$hybridOrder])['result'] === 'member', 'hybrid qualifies');

    // Real API-source fulfillment, with an in-process provider returning deterministic test delivery.
    $provider = new class implements SupplierInterface {
        public function key(): string { return 'referral-fixture'; }
        public function name(): string { return 'Fixture'; }
        public function isConfigured(): bool { return true; }
        public function getBalance(): array { return ['VND' => 100]; }
        public function getProducts(): array { return []; }
        public function getStock(string $remoteProductCode): int { return 10; }
        public function deliver(string $remoteProductCode, int $quantity, string $idempotencyKey): array {
            return ['success' => true, 'items' => array_fill(0, $quantity, 'remote-fixture-delivery'), 'provider_order_id' => $idempotencyKey];
        }
    };
    $property = new ReflectionProperty(SupplierRegistry::class, 'providers');
    $property->setValue($registry, ['referral-fixture' => $provider]);
    $db->execute('UPDATE supplier_sources SET status=0 WHERE variant_id=?', [$variantId]);
    $db->execute("INSERT INTO supplier_sources(variant_id,source_type,provider_key,remote_product_code,priority) VALUES(?,'api','referral-fixture','fixture',2)", [$variantId]);
    [$apiOrder] = $direct(10009, 'vietqr_direct', '1.00');
    $assert($db->fetch('SELECT status FROM orders WHERE id=?', [$apiOrder])['status'] === 'completed', 'API-source order delivered');
    $assert($db->fetch('SELECT result FROM referral_order_checks WHERE order_id=?', [$apiOrder])['result'] === 'member', 'API-source order qualifies');

    // Failed fulfillment never adds a membership check or reward.
    $db->execute('UPDATE supplier_sources SET status=0 WHERE variant_id=?', [$variantId]);
    [$failedOrder] = $direct(10010, 'vietqr_direct', '1.00');
    $assert($db->fetch('SELECT status FROM orders WHERE id=?', [$failedOrder])['status'] === 'failed', 'out of stock refunded');
    $assert($db->fetch('SELECT id FROM referral_order_checks WHERE order_id=?', [$failedOrder]) === null, 'failed fulfillment has no reward');

    $db->execute("UPDATE supplier_sources SET status=1 WHERE variant_id=? AND source_type='manual'", [$variantId]);
    $start(10012, $code);
    $setStatus(10012, 'api_error');
    $walletOrder($userId(10012), 'unresolved-membership');
    $assert($db->fetch('SELECT status FROM referrals WHERE invitee_id=?', [$userId(10012)])['status'] === 'verification', 'unresolved error retained for admin');

    // Configuration changes affect new referrals only, and cannot introduce invalid HTML.
    $updatedConfig = $config;
    $updatedConfig['reward'] = 1000;
    $service->saveConfig($updatedConfig);
    $assert((int) $db->fetch('SELECT reward_amount FROM referrals WHERE id=?', [$referral])['reward_amount'] === 500, 'existing reward snapshot retained');
    $start(10013, $code);
    $assert((int) $db->fetch('SELECT reward_amount FROM referrals WHERE invitee_id=?', [$userId(10013)])['reward_amount'] === 1000, 'new referral receives updated reward');
    $service->saveConfig($config);
    $badHtml = $config;
    $badHtml['instructions'] = '<vi><b>Broken</vi>';
    try { $service->saveConfig($badHtml); $assert(false, 'invalid instructions must fail'); }
    catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'referral_invalid_instructions', 'invalid HTML rejected'); }

    // Concurrency: two independent PHP processes attempt the same queued reward.
    $queuedId = (int) $db->fetch("SELECT id FROM referrals WHERE status='queued' ORDER BY id LIMIT 1")['id'];
    $beforeRollback = $db->fetch('SELECT balance FROM users WHERE id=?', [$inviter])['balance'];
    // Force failure at the final outbox insert to prove wallet/counters/status roll back together.
    $blockingOutbox = $outbox->enqueue($inviter, 'referral:' . $queuedId, 'referral_reward_received', []);
    try { $service->pay($queuedId); $assert(false, 'duplicate outbox must roll back payout'); }
    catch (PDOException) {
        $assert($db->fetch('SELECT balance FROM users WHERE id=?', [$inviter])['balance'] === $beforeRollback, 'failed payout rolls back wallet');
        $assert($db->fetch('SELECT status FROM referrals WHERE id=?', [$queuedId])['status'] === 'queued', 'failed payout remains queued');
        $assert((int) $db->fetch("SELECT COUNT(*) n FROM transactions WHERE reference_type='referral' AND reference_id=?", [$queuedId])['n'] === 0, 'failed payout rolls back transaction');
    }
    $db->execute('DELETE FROM notification_outbox WHERE id=?', [$blockingOutbox]);
    $command = [PHP_BINARY, '-n'];
    $extensionDir = ini_get('extension_dir');
    foreach (['pdo_mysql', 'mbstring', 'curl'] as $extension) {
        $command[] = '-d'; $command[] = 'extension_dir=' . $extensionDir;
        $command[] = '-d'; $command[] = 'extension=' . $extension;
    }
    $command = array_merge($command, [__FILE__, 'pay', $disposableDatabase, (string) $queuedId]);
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'concurrent payout process: ' . $output);
    }
    $assert((int) $db->fetch("SELECT COUNT(*) n FROM transactions WHERE reference_type='referral' AND reference_id=?", [$queuedId])['n'] === 1, 'concurrent payout exactly once');

    $config['daily_limit'] = 1;
    $service->saveConfig($config);
    $assert($service->payBatch() === 0, 'personal daily limit enforced');
    $config['daily_limit'] = 100;
    $config['daily_budget'] = 500;
    $service->saveConfig($config);
    $assert($service->payBatch() === 0, 'global daily budget enforced');
    // Simulate the prior day by moving usage rows, without changing server time.
    $db->execute('UPDATE referral_daily_usage SET usage_day=DATE_SUB(usage_day,INTERVAL 1 DAY)');
    $assert($service->payBatch() === 1, 'new day resumes queued payout within budget');
    $config['enabled'] = 0;
    $config['daily_budget'] = 50000;
    $service->saveConfig($config);
    $assert(!$service->needsGate($userId(10010)), 'disabling program lifts gate');
    $start(10011, $code);
    $assert($db->fetch('SELECT id FROM referrals WHERE invitee_id=?', [$userId(10011)]) === null, 'disabled program does not attribute');
    $assert($service->payBatch() > 0, 'already qualified rewards paid while disabled');
    $setStatus(10012, 'member');
    $assert($service->payBatch() === 0, 'late API recovery does not retroactively qualify an old order');

    $notice = $db->fetch("SELECT id FROM notification_outbox WHERE message_key='referral_reward_received' ORDER BY id LIMIT 1");
    $assert($outbox->deliver((int) $notice['id']), 'reward notification delivered');
    $assert(!$outbox->deliver((int) $notice['id']), 'notification replay rejected');
    (new ReferralHandler($db, $telegram, $service))->show(10001, 100, $inviter, new Language('vi'));
    $config['enabled'] = 1;
    $service->saveConfig($config);
    foreach (['vi', 'en'] as $language) {
        (new ReferralHandler($db, $telegram, $service))->show(10001, 100, $inviter, new Language($language));
        $lines = file($statePath . '.messages', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $sent = json_decode(end($lines), true)['payload'];
        $assert(str_contains($sent['text'], '?start=' . $code), 'dashboard exact deep link');
        $assert(!str_contains($sent['text'], '<vi>') && ReferralService::validHtml($sent['text']), 'dashboard language and HTML');
        $keyboard = json_decode($sent['reply_markup'], true)['inline_keyboard'];
        parse_str(parse_url($keyboard[0][0]['url'], PHP_URL_QUERY), $shareParams);
        $assert($shareParams['url'] === 'https://t.me/referral_test_bot?start=' . $code, 'share URL correctly encoded');
    }

    $badConfig = $config;
    $badConfig['channel_id'] = '-100987654';
    try { $service->saveConfig($badConfig); $assert(false, 'channel change must fail'); }
    catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'referral_channel_locked', 'unfinished referrals lock channel'); }

    // Render actual Admin module for visual inspection, including an unresolved exception.
    require_once __DIR__ . '/../admin/auth.php';
    $_SESSION = ['csrf' => 'test-csrf'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    foreach (['vi', 'en'] as $language) {
        $adminLanguage = new Language($language);
        ob_start();
        include __DIR__ . '/../admin/modules/referrals.php';
        $body = ob_get_clean();
        $html = '<!doctype html><html lang="' . $language . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../../admin/assets/style.css"></head><body><div class="layout"><aside><nav><a href="#">' . $adminLanguage->get('admin_nav_referrals') . '</a></nav></aside><main>' . $body . '</main></div></body></html>';
        file_put_contents(__DIR__ . '/../tmp/referral-tools/admin-' . $language . '.html', $html);
        $assert(!str_contains($body, 'minimum_order'), 'no minimum-order UI');
    }

    // Verify in-place migration preserves existing user data.
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0; DROP TABLE referral_order_checks,referral_daily_usage,referrals,referral_codes; SET FOREIGN_KEY_CHECKS=1');
    $db->execute("DELETE FROM schema_migrations WHERE migration_name='015_referral_rewards.sql'");
    $db->execute("DELETE FROM settings WHERE key_name='referral_config'");
    $executed = (new MigrationRunner($db, __DIR__ . '/../migrations'))->run();
    $assert($executed === ['015_referral_rewards.sql'], 'upgrade migration runs once');
    $assert($db->fetch('SELECT balance FROM users WHERE id=?', [$inviter]) !== null, 'upgrade preserves users');
    $assert((new MigrationRunner($db, __DIR__ . '/../migrations'))->run() === [], 'upgrade repeat no-op');
    echo "OK: {$count} MySQL referral integration assertions\n";
} finally {
    $server->exec('DROP DATABASE `' . $disposableDatabase . '`');
}
