<?php

declare(strict_types=1);

final class DepositHandler
{
    public function __construct(private Database $db, private Telegram $telegram, private VietQR $vietQr, private int $minDeposit)
    {
    }

    public function show(int $chatId, int $messageId, int $userId, Language $lang): void
    {
        $deposit = $this->db->transaction(function (Database $db) use ($userId): array {
            $db->fetch('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $pending = $db->fetch(
                "SELECT * FROM deposits WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [$userId]
            );
            if ($pending !== null) {
                return $pending;
            }

            do {
                $code = Helpers::randomCode('DONATE');
                $existsInDeposits = $db->fetch('SELECT id FROM deposits WHERE code = ?', [$code]) !== null;
                $existsInOrders = $db->fetch('SELECT id FROM orders WHERE payment_code = ?', [$code]) !== null;
            } while ($existsInDeposits || $existsInOrders);

            $db->execute(
                "INSERT INTO deposits (user_id, code, status) VALUES (?, ?, 'pending')",
                [$userId, $code]
            );

            return [
                'id' => $db->lastInsertId(),
                'code' => $code,
            ];
        });
        $bank = $this->vietQr->transferDetails();
        $caption = '<blockquote><b>' . $lang->get('deposit_title') . "</b></blockquote>\n\n"
            . $lang->get('transfer_content') . ': <code>' . $deposit['code'] . "</code>\n"
            . '🏦 Bank: <b>' . Helpers::escape($bank['bank_id']) . '</b> | STK: <code>'
            . Helpers::escape($bank['account_number']) . "</code>\n"
            . $lang->get('minimum') . ': <b>' . Helpers::money($this->minDeposit) . "</b>\n\n"
            . $lang->get('deposit_code_note');
        $keyboard = [[['text' => $lang->get('main_menu'), 'callback_data' => 'start']]];

        try {
            $this->telegram->replaceWithPhoto($chatId, $messageId, $this->vietQr->imageUrl($deposit['code']), $caption, $keyboard);
        } catch (Throwable $error) {
            error_log('Deposit QR delivery failed: ' . $error::class);
            $text = $caption . "\n\n<i>" . $lang->get('qr_unavailable') . "</i>\n\n"
                . '<b>' . $lang->get('bank') . ':</b> ' . Helpers::escape($bank['bank_id']) . "\n"
                . '<b>' . $lang->get('account_number') . ':</b> <code>' . Helpers::escape($bank['account_number']) . "</code>\n"
                . '<b>' . $lang->get('account_name') . ':</b> ' . Helpers::escape($bank['account_name']);
            $this->telegram->editMessage($chatId, $messageId, $text, $keyboard);
        }
    }
}
