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

            $registry = new PaymentCodeRegistry($db);
            $code = $registry->reserve('deposit');
            $db->execute(
                "INSERT INTO deposits (user_id, code, status) VALUES (?, ?, 'pending')",
                [$userId, $code]
            );
            $depositId = $db->lastInsertId();
            $registry->bind($code, 'deposit', $depositId);

            return [
                'id' => $depositId,
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
            $result = $this->telegram->replaceWithPhoto($chatId, $messageId, $qrUrl, $content, $keyboard);
            $sentMessageId = (int) ($result['result']['message_id'] ?? 0);
            if ($sentMessageId > 0) {
                $this->recordPaymentMessage((int) $deposit['id'], $userId, $chatId, $sentMessageId, 'photo', $qrUrl);
            }
        } catch (Throwable $error) {
            error_log('Deposit QR delivery failed: ' . $error::class);
            $fallbackKeyboard = [
                [['text' => $lang->get('open_qr'), 'url' => $qrUrl]],
                ...$keyboard,
            ];
            $text = $content . "\n\n<i>" . $lang->get('qr_unavailable') . '</i>';
            $result = $this->telegram->editMessage($chatId, $messageId, $text, $fallbackKeyboard);
            $sentMessageId = (int) ($result['result']['message_id'] ?? $messageId);
            if ($sentMessageId > 0) {
                $this->recordPaymentMessage((int) $deposit['id'], $userId, $chatId, $sentMessageId, 'text', $qrUrl);
            }
        }
    }

    private function recordPaymentMessage(
        int $depositId,
        int $userId,
        int $chatId,
        int $messageId,
        string $messageType,
        string $qrUrl
    ): void {
        $this->db->transaction(function (Database $db) use ($depositId, $userId, $chatId, $messageId, $messageType, $qrUrl): void {
            $deposit = $db->fetch('SELECT status FROM deposits WHERE id=? AND user_id=? FOR UPDATE', [$depositId, $userId]);
            if ($deposit === null || $deposit['status'] !== 'pending') return;
            $db->execute(
                "UPDATE payment_message_links SET is_active=0,cleanup_status='superseded' WHERE request_type='deposit' AND request_id=? AND is_active=1",
                [$depositId]
            );
            $db->execute(
                "INSERT INTO payment_message_links(request_type,request_id,user_id,chat_id,message_id,message_type,qr_url)
                 VALUES('deposit',?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE request_type=VALUES(request_type),request_id=VALUES(request_id),user_id=VALUES(user_id),message_type=VALUES(message_type),qr_url=VALUES(qr_url),is_active=1,cleanup_status='pending',cleanup_attempts=0,last_error_code=NULL,cleaned_at=NULL",
                [$depositId, $userId, $chatId, $messageId, $messageType, $qrUrl]
            );
        });
    }
}
