<?php

declare(strict_types=1);

$service = new ReferralService($db, $telegram);
$t = static fn (string $key, array $params = []): string => $adminLanguage->get($key, $params);
$errorMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        if (($_POST['action'] ?? '') === 'approve') {
            $approved = $service->approveException((int) ($_POST['check_id'] ?? 0), trim((string) ($_POST['reason'] ?? '')), (string) $app['admin_username']);
            redirectAdmin('referrals', $t($approved ? 'referral_reviewed' : 'referral_review_invalid'));
        }
        $service->saveConfig($_POST);
        redirectAdmin('referrals', $t('referral_saved'));
    } catch (InvalidArgumentException $error) {
        $errorMessage = $t($error->getMessage());
    } catch (Throwable $error) {
        error_log('Referral admin action failed: ' . $error::class);
        $errorMessage = $t('referral_operation_error');
    }
}
$config = $service->config();
$values = $errorMessage !== null && ($_POST['action'] ?? '') === 'save' ? $_POST : $config;
$search = trim((string) ($_GET['search'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$statuses = ['waiting', 'verification', 'queued', 'paid'];
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(CAST(i.telegram_id AS CHAR)=? OR CAST(u.telegram_id AS CHAR)=?)';
    $params = [$search, $search];
}
if (in_array($status, $statuses, true)) {
    $where[] = 'r.status=?';
    $params[] = $status;
}
$from = ' FROM referrals r JOIN users i ON i.id=r.inviter_id JOIN users u ON u.id=r.invitee_id';
$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
$total = (int) $db->fetch('SELECT COUNT(*) total' . $from . $whereSql, $params)['total'];
$pages = max(1, (int) ceil($total / 20));
$pageNumber = min($pages, max(1, (int) ($_GET['p'] ?? 1)));
$rows = $db->fetchAll(
    'SELECT r.*,i.telegram_id inviter_telegram,i.username inviter_username,u.telegram_id invitee_telegram,u.username invitee_username,
     c.id check_id,c.reason,c.result,c.review_reason,c.reviewed_by,c.checked_at,o.order_code'
    . $from . ' LEFT JOIN referral_order_checks c ON c.id=(SELECT c2.id FROM referral_order_checks c2
        WHERE c2.referral_id=r.id ORDER BY (c2.order_id=r.eligible_order_id) DESC,
        (c2.result=\'api_error\' AND c2.reviewed_at IS NULL AND r.status=\'verification\') DESC,c2.id DESC LIMIT 1)
     LEFT JOIN orders o ON o.id=c.order_id' . $whereSql . ' ORDER BY r.id DESC LIMIT 20 OFFSET ' . (($pageNumber - 1) * 20), $params
);
$stats = $db->fetch("SELECT COALESCE(SUM(CASE WHEN status='paid' THEN reward_amount ELSE 0 END),0) paid,
    COALESCE(SUM(CASE WHEN status='queued' THEN reward_amount ELSE 0 END),0) queued,
    COALESCE(SUM(status='verification'),0) verification FROM referrals");
$day = date('Y-m-d');
$today = $db->fetch('SELECT reward_amount FROM referral_daily_usage WHERE usage_day=? AND inviter_id=0', [$day]);
$pageUrl = static fn (int $page): string => '?' . http_build_query(['page' => 'referrals', 'search' => $search, 'status' => $status, 'p' => $page]);
?>
<h1><?= e($t('admin_nav_referrals')) ?></h1>
<?php if ($errorMessage !== null): ?><p class="error-message" role="alert"><?= e($errorMessage) ?></p><?php endif; ?>
<section class="card">
    <h2><?= e($t('referral_settings')) ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <label class="inline-check"><input type="checkbox" name="enabled" value="1" <?= !empty($values['enabled']) ? 'checked' : '' ?>><?= e($t('referral_enabled')) ?></label>
        <div class="form-grid">
            <?php foreach (['reward' => 'referral_amount', 'daily_limit' => 'referral_daily_limit', 'daily_budget' => 'referral_daily_budget'] as $name => $label): ?>
                <div><label for="referral_<?= e($name) ?>"><?= e($t($label)) ?></label><input id="referral_<?= e($name) ?>" name="<?= e($name) ?>" type="number" min="0" max="999999999999" step="1" required value="<?= e($values[$name] ?? 0) ?>"></div>
            <?php endforeach; ?>
            <div><label for="referral_channel_id"><?= e($t('referral_channel_id')) ?></label><input id="referral_channel_id" name="channel_id" maxlength="100" value="<?= e($values['channel_id'] ?? '') ?>"></div>
        </div>
        <label for="referral_channel_url"><?= e($t('referral_channel_url')) ?></label>
        <input id="referral_channel_url" name="channel_url" type="url" maxlength="250" value="<?= e($values['channel_url'] ?? '') ?>">
        <label for="referral_instructions"><?= e($t('referral_instructions')) ?></label>
        <textarea id="referral_instructions" name="instructions" rows="9" maxlength="2400" aria-describedby="referral_instructions_help"><?= e($values['instructions'] ?? '') ?></textarea>
        <small class="help-text" id="referral_instructions_help"><?= e($t('referral_instructions_help')) ?></small>
        <p><?= e($t('referral_policy_help')) ?></p>
        <button type="submit" class="primary"><?= e($t('referral_save')) ?></button>
    </form>
</section>
<section class="card">
    <h2><?= e($t('referral_list')) ?></h2>
    <p><?= e($t('referral_totals', ['paid' => Helpers::money($stats['paid']), 'queued' => Helpers::money($stats['queued']), 'verification' => $stats['verification']])) ?></p>
    <p><?= e($t('referral_today', ['amount' => Helpers::money($today['reward_amount'] ?? 0), 'budget' => Helpers::money($config['daily_budget'])])) ?></p>
    <form method="get" class="toolbar">
        <input type="hidden" name="page" value="referrals">
        <input name="search" value="<?= e($search) ?>" placeholder="<?= e($t('referral_search')) ?>" aria-label="<?= e($t('referral_search')) ?>">
        <select name="status" aria-label="<?= e($t('referral_status')) ?>">
            <option value=""><?= e($t('referral_all_statuses')) ?></option>
            <?php foreach ($statuses as $item): ?><option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($t('referral_status_' . $item)) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="primary"><?= e($t('referral_filter')) ?></button>
        <a class="button secondary" href="?page=referrals"><?= e($t('referral_reset')) ?></a>
    </form>
    <div class="table-wrap" tabindex="0" role="region" aria-label="<?= e($t('referral_list')) ?>">
        <table class="referral-table">
            <thead><tr><?php foreach (['referral_inviter', 'referral_invitee', 'referral_reward', 'referral_status', 'referral_order', 'referral_check_result', 'referral_review'] as $label): ?><th scope="col"><?= e($t($label)) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php if ($rows === []): ?><tr><td colspan="7"><?= e($t('referral_empty')) ?></td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['inviter_telegram']) ?><br><span class="stock-content"><?= e($row['inviter_username'] ?? '') ?></span></td>
                    <td><?= e($row['invitee_telegram']) ?><br><span class="stock-content"><?= e($row['invitee_username'] ?? '') ?></span></td>
                    <td><?= e(Helpers::money($row['reward_amount'])) ?></td>
                    <td><?= e($t('referral_status_' . $row['status'])) ?></td>
                    <td><?= e($row['order_code'] ?? '—') ?></td>
                    <td><span class="stock-content"><?= e($t('referral_reason_' . ($row['reason'] ?? 'none'))) ?></span><?= e(Helpers::dateTime($row['checked_at'] ?? null)) ?></td>
                    <td>
                        <?php if ($row['result'] === 'api_error' && in_array($row['status'], ['waiting', 'verification'], true)): ?>
                        <form method="post" class="toolbar">
                            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="check_id" value="<?= e($row['check_id']) ?>">
                            <input name="reason" required maxlength="500" placeholder="<?= e($t('referral_review_reason')) ?>" aria-label="<?= e($t('referral_review_reason')) ?>">
                            <button type="submit" class="secondary"><?= e($t('referral_review')) ?></button>
                        </form>
                        <small class="help-text"><?= e($t('referral_review_help')) ?></small>
                        <?php elseif ($row['review_reason'] !== null): ?><span class="stock-content"><?= e($row['reviewed_by']) ?>: <?= e($row['review_reason']) ?></span><?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <nav class="toolbar" aria-label="<?= e($t('referral_list')) ?>">
        <a class="button secondary <?= $pageNumber <= 1 ? 'disabled' : '' ?>" href="<?= e($pageUrl(max(1, $pageNumber - 1))) ?>" aria-disabled="<?= $pageNumber <= 1 ? 'true' : 'false' ?>" <?= $pageNumber <= 1 ? 'tabindex="-1"' : '' ?>><?= e($t('referral_previous')) ?></a>
        <span><?= e($t('referral_page', ['current' => $pageNumber, 'total' => $pages])) ?></span>
        <a class="button secondary <?= $pageNumber >= $pages ? 'disabled' : '' ?>" href="<?= e($pageUrl(min($pages, $pageNumber + 1))) ?>" aria-disabled="<?= $pageNumber >= $pages ? 'true' : 'false' ?>" <?= $pageNumber >= $pages ? 'tabindex="-1"' : '' ?>><?= e($t('referral_next')) ?></a>
    </nav>
</section>
