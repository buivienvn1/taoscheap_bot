<?php

declare(strict_types=1);

final class Telegram
{
    private string $apiUrl;
    private CurlHandle $curl;

    public function __construct(string $token)
    {
        $this->apiUrl = 'https://api.telegram.org/bot' . $token . '/';
        $this->curl = curl_init();
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

    public function sendMessage(int|string $chatId, string $text, array $keyboard = []): array
    {
        return $this->request('sendMessage', $this->messagePayload($chatId, $text, $keyboard));
    }

    public function editMessage(int|string $chatId, int $messageId, string $text, array $keyboard = []): array
    {
        return $this->request('editMessageText', array_merge(
            $this->messagePayload($chatId, $text, $keyboard),
            ['message_id' => $messageId]
        ));
    }

    public function sendPhoto(int|string $chatId, string $photo, string $caption, array $keyboard = []): array
    {
        $payload = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];
        if ($keyboard !== []) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }
        return $this->request('sendPhoto', $payload);
    }

    public function answerCallback(string $callbackId): array
    {
        return $this->request('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->request('setWebhook', ['url' => $url, 'secret_token' => $secret]);
    }

    private function messagePayload(int|string $chatId, string $text, array $keyboard): array
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($keyboard !== []) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }
        return $payload;
    }

    private function request(string $method, array $payload): array
    {
        curl_reset($this->curl);
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->apiUrl . $method,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($this->curl);
        $error = curl_error($this->curl);

        if ($body === false) {
            throw new RuntimeException('Telegram API error: ' . $error);
        }

        $result = json_decode($body, true);
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            throw new RuntimeException('Telegram API rejected request: ' . $body);
        }

        return $result;
    }
}
