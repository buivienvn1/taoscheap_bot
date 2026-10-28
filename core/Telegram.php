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

        try {
            return $this->request('sendPhoto', $payload);
        } catch (RuntimeException $error) {
            if (!str_contains($error->getMessage(), 'failed to get HTTP URL content')) {
                throw $error;
            }
        }

        $file = $this->downloadImage($photo);
        try {
            $payload['photo'] = new CURLFile($file, 'image/png', 'vietqr.png');
            return $this->request('sendPhoto', $payload);
        } finally {
            @unlink($file);
        }
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

    private function downloadImage(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'img.vietqr.io') {
            throw new RuntimeException('QR image host is not allowed.');
        }

        $file = tempnam(sys_get_temp_dir(), 'vietqr_');
        if ($file === false) {
            throw new RuntimeException('Unable to create temporary QR image.');
        }

        $handle = fopen($file, 'wb');
        if ($handle === false) {
            @unlink($file);
            throw new RuntimeException('Unable to open temporary QR image.');
        }

        $size = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use ($handle, &$size): int {
                $size += strlen($data);
                if ($size > 5 * 1024 * 1024) {
                    return 0;
                }
                return fwrite($handle, $data);
            },
        ]);
        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        fclose($handle);

        $imageInfo = @getimagesize($file);
        if ($success === false || $status !== 200 || $size === 0 || $size > 5 * 1024 * 1024
            || !str_starts_with($contentType, 'image/') || $imageInfo === false) {
            @unlink($file);
            throw new RuntimeException('Unable to download a valid QR image.');
        }

        return $file;
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
            $description = (string) ($result['description'] ?? '');
            if (isset($payload['parse_mode']) && str_contains($description, "can't parse entities")) {
                unset($payload['parse_mode']);
                foreach (['text', 'caption'] as $field) {
                    if (isset($payload[$field])) {
                        $payload[$field] = html_entity_decode(
                            strip_tags((string) $payload[$field]),
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        );
                    }
                }
                return $this->request($method, $payload);
            }
            throw new RuntimeException('Telegram API rejected request: ' . $body);
        }

        return $result;
    }
}
