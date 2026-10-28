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
        $qrUrl = $this->vietQr->imageUrl((string) $deposit['code']);
        $content = '<blockquote><b>' . $lang->get('deposit_title') . "</b></blockquote>\n\n"
            . $lang->get('payment_bank_line', [
                'bank' => Helpers::escape($bank['bank_id']),
                'account' => Helpers::escape($bank['account_number']),
            ]) . "\n"
            . $lang->get('payment_content_line', [
                'content' => Helpers::escape((string) $deposit['code']),
            ]) . "\n"
            . $lang->get('payment_minimum_line', [
                'amount' => Helpers::money($this->minDeposit),
            ]) . "\n\n"
            . $lang->get('payment_instruction');
        $keyboard = [[['text' => $lang->get('main_menu'), 'callback_data' => 'start']]];

        try {
            $this->telegram->replaceWithPhoto($chatId, $messageId, $qrUrl, $content, $keyboard);
        } catch (Throwable $error) {
            error_log('Deposit QR delivery failed: ' . $error::class);
            $fallbackKeyboard = [
                [['text' => $lang->get('open_qr'), 'url' => $qrUrl]],
                ...$keyboard,
            ];
            $text = $content . "\n\n<i>" . $lang->get('qr_unavailable') . '</i>';
            $this->telegram->editMessage($chatId, $messageId, $text, $fallbackKeyboard);
        }
    }
}
