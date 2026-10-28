<?php

$keys = [
    'shop_name',
    'shop_intro',
    'shop_intro_en',
    'owner_username',
    'channel_username',
    'website',
    'support_text',
    'support_text_en',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'webhook') {
        $url = rtrim($app['app_url'], '/') . '/webhook.php';
        $telegram->setWebhook($url, $app['telegram_secret']);
        redirectAdmin('settings', 'Đã kích hoạt webhook.');
    }

    if ($action === 'migrate') {
        try {
            $executed = (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->run();
            $message = $executed === []
                ? 'Database đã ở phiên bản mới nhất.'
                : 'Đã chạy: ' . implode(', ', $executed);
        } catch (Throwable $error) {
            $message = 'Migration thất bại: ' . $error->getMessage();
        }
        redirectAdmin('settings', $message);
    }

    foreach ($keys as $key) {
        $db->execute(
            'INSERT INTO settings(key_name,key_value) VALUES(?,?) ON DUPLICATE KEY UPDATE key_value=VALUES(key_value)',
            [$key, trim((string) ($_POST[$key] ?? ''))]
        );
    }
    redirectAdmin('settings', 'Đã lưu cài đặt.');
}

$rows = $db->fetchAll('SELECT key_name,key_value FROM settings');
$values = array_column($rows, 'key_value', 'key_name');
$htmlTags = '<b>, <i>, <u>, <s>, <tg-spoiler>, <a>, <code>, <pre>, <blockquote>';
try {
    $migrationStatus = (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->status();
    $migrationError = null;
} catch (Throwable $error) {
    $migrationStatus = ['applied' => [], 'pending' => [], 'total' => 0];
    $migrationError = $error->getMessage();
}
?>
<h1>Cài đặt</h1>
<div class="card">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

        <h3>Thông tin cửa hàng</h3>
        <label for="shop_name">Tên cửa hàng</label>
        <input id="shop_name" name="shop_name" value="<?= e($values['shop_name'] ?? '') ?>">

        <div class="form-grid">
            <div>
                <label for="owner_username">Telegram chủ shop</label>
                <input id="owner_username" name="owner_username" value="<?= e($values['owner_username'] ?? '') ?>" placeholder="@username">
            </div>
            <div>
                <label for="channel_username">Kênh Telegram</label>
                <input id="channel_username" name="channel_username" value="<?= e($values['channel_username'] ?? '') ?>" placeholder="@channel">
            </div>
        </div>

        <label for="website">Website</label>
        <input id="website" name="website" value="<?= e($values['website'] ?? '') ?>" placeholder="https://example.com">

        <h3>Nội dung giới thiệu</h3>
        <label for="shop_intro">Giới thiệu tiếng Việt</label>
        <textarea id="shop_intro" name="shop_intro" rows="5"><?= e($values['shop_intro'] ?? '') ?></textarea>
        <small class="help-text">Hỗ trợ HTML Telegram: <?= e($htmlTags) ?>.</small>

        <label for="shop_intro_en">Giới thiệu tiếng Anh</label>
        <textarea id="shop_intro_en" name="shop_intro_en" rows="5"><?= e($values['shop_intro_en'] ?? '') ?></textarea>
        <small class="help-text">Hỗ trợ HTML Telegram: <?= e($htmlTags) ?>.</small>

        <h3>Hỗ trợ khách hàng</h3>
        <label for="support_text">Nội dung hỗ trợ tiếng Việt</label>
        <textarea id="support_text" name="support_text" rows="4"><?= e($values['support_text'] ?? '') ?></textarea>

        <label for="support_text_en">Nội dung hỗ trợ tiếng Anh</label>
        <textarea id="support_text_en" name="support_text_en" rows="4"><?= e($values['support_text_en'] ?? '') ?></textarea>

        <button type="submit">Lưu cài đặt</button>
    </form>
</div>

<div class="card">
    <h3>Database Migration</h3>
    <?php if ($migrationError !== null): ?>
        <p class="error-message"><?= e($migrationError) ?></p>
    <?php else: ?>
        <p>Đã áp dụng <?= e(count($migrationStatus['applied'])) ?>/<?= e($migrationStatus['total']) ?> migration.</p>
        <?php if ($migrationStatus['pending'] !== []): ?>
            <p>Đang chờ: <code><?= e(implode(', ', $migrationStatus['pending'])) ?></code></p>
        <?php else: ?>
            <p class="success-text">Database đã ở phiên bản mới nhất.</p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="migrate">
            <button type="submit">Kiểm tra và chạy Migration</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Telegram Webhook</h3>
    <p><code><?= e(rtrim($app['app_url'], '/') . '/webhook.php') ?></code></p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="webhook">
        <button type="submit">Kích hoạt Webhook</button>
    </form>
</div>
