<?php

declare(strict_types=1);

final class DepositHandler
{
    public function __construct(private Database $db, private Telegram $telegram, private VietQR $vietQr, private int $minDeposit)
    {
    }

    public function show(int $chatId, int $userId): void
    {
        $deposit = $this->db->fetch("SELECT * FROM deposits WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId]);
        if ($deposit === null) {
            do {
                $code = Helpers::randomCode();
            } while ($this->db->fetch('SELECT id FROM deposits WHERE code = ?', [$code]) !== null);
            $this->db->execute("INSERT INTO deposits (user_id, code, status) VALUES (?, ?, 'pending')", [$userId, $code]);
            $deposit = ['code' => $code];
        }
        $caption = "<blockquote>NẠP TIỀN VÀO VÍ</blockquote>\n\nNội dung: <code>" . $deposit['code'] . "</code>\nTối thiểu: <b>" . Helpers::money($this->minDeposit) . "</b>\n\nMã được giữ đến khi nạp thành công.";
        $this->telegram->sendPhoto($chatId, $this->vietQr->imageUrl($deposit['code']), $caption, [[['text' => '⬅ Menu chính', 'callback_data' => 'start']]]);
    }
}
