<?php

declare(strict_types=1);

final class ReferralHandler
{
    private MoneyFormatter $money;

    public function __construct(private Database $db, private Telegram $telegram, private ReferralService $referrals)
    {
        $this->money = new MoneyFormatter($db);
    }

    public function show(int $chatId, int $messageId, int $userId, Language $lang): void
    {
        $config = $this->referrals->config();
        if (!$config['enabled']) {
            $this->telegram->editMessage($chatId, $messageId, $lang->get('referral_paused'), [
                [['text' => $lang->get('back'), 'callback_data' => 'start']],
            ]);
            return;
        }
        $stats = $this->db->fetch(
            "SELECT COUNT(*) invited,COALESCE(SUM(status='paid'),0) rewarded,
             COALESCE(SUM(CASE WHEN status='paid' THEN reward_amount ELSE 0 END),0) earned FROM referrals WHERE inviter_id=?", [$userId]
        );
        // The dashboard advertises the current rate for newly invited friends.
        $reward = (int) $config['reward'];
        $link = 'https://t.me/' . $config['bot_username'] . '?start=' . $this->referrals->code($userId);
        $preference = $this->money->preference($userId);
        $text = $lang->get('referral_dashboard', [
            'reward' => Helpers::escape($this->money->format($reward, $preference)), 'invited' => (int) $stats['invited'],
            'rewarded' => (int) $stats['rewarded'], 'earned' => Helpers::escape($this->money->format($stats['earned'], $preference)),
            'link' => Helpers::escape($link),
        ]) . "\n\n" . $lang->get('referral_how') . "\n" . ReferralService::instructions(
            $config['instructions'],
            $lang,
            $reward,
            $this->money->format($reward, $preference)
        );
        $share = 'https://t.me/share/url?' . http_build_query(['url' => $link, 'text' => $lang->get('referral_share_message')], '', '&', PHP_QUERY_RFC3986);
        $this->telegram->editMessage($chatId, $messageId, $text, [
            [['text' => $lang->get('referral_share'), 'url' => $share]],
            [['text' => $lang->get('referral_refresh'), 'callback_data' => 'referral']],
            [['text' => $lang->get('back'), 'callback_data' => 'start']],
        ]);
    }

    public function gate(int $chatId, ?int $messageId, Language $lang, string $fullName, ?string $notice = null): void
    {
        $text = $lang->get('referral_gate', ['name' => Helpers::escape($fullName)]);
        if ($notice !== null) $text .= "\n\n" . $lang->get($notice);
        $keyboard = [
            [['text' => $lang->get('referral_join'), 'url' => $this->referrals->config()['channel_url']]],
            [['text' => $lang->get('referral_check'), 'callback_data' => 'referral_check']],
            [['text' => $lang->get('btn_language'), 'callback_data' => 'language'], ['text' => $lang->get('btn_support'), 'callback_data' => 'support']],
        ];
        if ($messageId === null) $this->telegram->sendMessage($chatId, $text, $keyboard);
        else $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
    }
}
