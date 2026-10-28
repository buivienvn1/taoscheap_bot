<?php

declare(strict_types=1);

final class DepositHandler
{
    public function __construct(private Database $db, private Telegram $telegram, private VietQR $vietQr, private int $minDeposit)
    {
    }

    public function show(int $chatId, int $userId, Language $lang): void
    {
        $deposit = $this->db->fetch("SELECT * FROM deposits WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId]);
        if ($deposit === null) {
            do {
                $code = Helpers::randomCode();
            } while ($this->db->fetch('SELECT id FROM deposits WHERE code = ?', [$code]) !== null);
            $this->db->execute("INSERT INTO deposits (user_id, code, status) VALUES (?, ?, 'pending')", [$userId, $code]);
            $deposit = ['code' => $code];
        }
        $caption = '<blockquote>' . $lang->get('deposit_title') . "</blockquote>\n\n"
            . $lang->get('transfer_content') . ': <code>' . $deposit['code'] . "</code>\n"
            . $lang->get('minimum') . ': <b>' . Helpers::money($this->minDeposit) . "</b>\n\n"
            . $lang->get('deposit_code_note');
        $this->telegram->sendPhoto($chatId, $this->vietQr->imageUrl($deposit['code']), $caption, [[['text' => $lang->get('main_menu'), 'callback_data' => 'start']]]);
    }
}
