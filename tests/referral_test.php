<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/Language.php';
require_once __DIR__ . '/../core/ReferralService.php';

$count = 0;
$assert = static function (bool $condition, string $name) use (&$count): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    $count++;
};
foreach (['member', 'creator', 'administrator'] as $status) {
    $assert(ReferralService::isMember(['status' => $status]), 'accept ' . $status);
}
foreach (['left', 'kicked', 'unknown', 'restricted'] as $status) {
    $assert(!ReferralService::isMember(['status' => $status]), 'reject ' . $status);
}
$assert(ReferralService::isMember(['status' => 'restricted', 'is_member' => true]), 'restricted member');
$assert(!ReferralService::isMember(['status' => 'restricted', 'is_member' => false]), 'restricted nonmember');
$vi = new Language('vi');
$en = new Language('en');
$assert($vi->get('referral_title') === '🎁 Mời bạn & Kiếm tiền', 'Vietnamese title');
$assert($en->get('referral_title') === '🎁 Refer & Earn', 'English title');
$raw = '<vi><b>Thưởng {reward}</b></vi><en><i>Earn {reward}</i></en>';
$assert(ReferralService::instructions($raw, $vi, 500) === '<b>Thưởng 500 VND</b>', 'VI selection and amount');
$assert(ReferralService::instructions($raw, $en, 500) === '<i>Earn 500 VND</i>', 'EN selection and amount');
$assert(ReferralService::instructions('<vi>Chỉ tiếng Việt</vi>', $en, 1) === 'Chỉ tiếng Việt', 'missing language fallback');
$assert(ReferralService::instructions('Plain {reward}', $vi, 1) === 'Plain 1 VND', 'plain instructions');
$assert(str_contains(ReferralService::instructions('', $vi, 1), '1 VND'), 'default instructions');
$assert(!str_contains(ReferralService::instructions('<script>bad</script><b>Safe</b>', $en, 1), '<script>'), 'unsafe tags removed');
$assert(!str_contains(ReferralService::instructions('<a href="javascript:bad">x</a>', $en, 1), 'javascript:'), 'unsafe URL removed');
$assert(ReferralService::validHtml('<b>x</b><a href="https://example.com">y</a>'), 'balanced HTML');
$assert(!ReferralService::validHtml('<b>x'), 'unclosed tag');
$assert(!ReferralService::validHtml('<b><i>x</b></i>'), 'crossed tags');
$assert(!ReferralService::validHtml('</b>'), 'unexpected closing tag');
$viKeys = array_keys(require __DIR__ . '/../languages/vi.php');
$enKeys = array_keys(require __DIR__ . '/../languages/en.php');
$referralKeys = static fn (array $keys): array => array_values(array_filter($keys, static fn (string $key): bool => str_contains($key, 'referral')));
$assert($referralKeys($viKeys) === $referralKeys($enKeys), 'referral translation parity');
foreach ([$vi, $en] as $lang) {
    $dashboard = $lang->get('referral_dashboard', ['reward' => '500 VND', 'invited' => 0, 'rewarded' => 0, 'earned' => '0 VND', 'link' => Helpers::escape('https://t.me/test?start=ABC123DEF456')]);
    $assert(ReferralService::validHtml($dashboard), 'dashboard HTML');
    $assert(simplexml_load_string('<root>' . $dashboard . '</root>') !== false, 'dashboard HTML entities escaped');
    $assert(!str_contains($dashboard, ':reward'), 'dashboard placeholders filled');
    $assert(!str_contains($dashboard, '500 VNDed'), 'overlapping placeholders replaced safely');
    $assert(str_contains($dashboard, '<code>https://t.me/test?start=ABC123DEF456</code>'), 'invite link is copyable');
}
$assert(str_contains($vi->get('referral_dashboard', ['reward' => '200 VND', 'invited' => 0, 'rewarded' => 0, 'earned' => '0 VND', 'link' => 'link']), 'Đã thưởng: 0'), 'Vietnamese rewarded count');
$assert(str_contains($en->get('referral_dashboard', ['reward' => '200 VND', 'invited' => 0, 'rewarded' => 0, 'earned' => '0 VND', 'link' => 'link']), 'Rewarded: 0'), 'English rewarded count');
$assert($vi->get('referral_share_message') === 'Tham gia và xác minh để nhận thưởng', 'Vietnamese share message');
$assert($en->get('referral_share_message') === 'Join and verify to earn rewards', 'English share message');
$assert(str_contains($vi->get('referral_gate', ['name' => 'An & Bình']), 'An & Bình'), 'Vietnamese personalized gate');
$assert(str_contains($en->get('referral_gate', ['name' => 'An & Bình']), 'An & Bình'), 'English personalized gate');
echo "OK: {$count} referral assertions\n";
