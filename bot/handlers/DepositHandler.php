<?php

declare(strict_types=1);

final class DepositHandler
{
    public function __construct(private Database $db, private Telegram $telegram, private VietQR $vietQr, private int $minDeposit)
    {
    }

    public function show(int $chatId, int $messageId, int $userId, Language $lang): void
    {
        $userRef = substr(hash('sha256', 'deposit-user:' . $userId), 0, 12);
        $statusRows = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS total FROM deposits WHERE user_id = ? GROUP BY status',
            [$userId]
        );
        $statusCounts = [
            'pending' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];
        foreach ($statusRows as $statusRow) {
            $status = (string) ($statusRow['status'] ?? '');
            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status] = (int) $statusRow['total'];
            }
        }

        $deposit = $this->db->fetch("SELECT * FROM deposits WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId]);
        error_log(sprintf(
            'Deposit lookup [user_ref=%s pending=%d completed=%d cancelled=%d selected_id=%s]',
            $userRef,
            $statusCounts['pending'],
            $statusCounts['completed'],
            $statusCounts['cancelled'],
            $deposit === null ? 'none' : (string) $deposit['id']
        ));

        if ($deposit === null) {
            do {
                $code = Helpers::randomCode();
            } while ($this->db->fetch('SELECT id FROM deposits WHERE code = ?', [$code]) !== null);
            $this->db->execute("INSERT INTO deposits (user_id, code, status) VALUES (?, ?, 'pending')", [$userId, $code]);
            $depositId = $this->db->lastInsertId();
            $deposit = ['id' => $depositId, 'code' => $code];
            error_log(sprintf(
                'Deposit created [user_ref=%s deposit_id=%d]',
                $userRef,
                $depositId
            ));
        } else {
            error_log(sprintf(
                'Deposit reused [user_ref=%s deposit_id=%d]',
                $userRef,
                (int) $deposit['id']
            ));
        }
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
