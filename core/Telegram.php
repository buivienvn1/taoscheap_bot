<?php

declare(strict_types=1);

final class Telegram
{
    private const QR_CACHE_TTL = 2592000;
    private const QR_RENDERER_VERSION = 'vietqr-svg-gd-v2';

    private string $apiUrl;
    private string $qrCacheFile;
    private CurlHandle $curl;

    public function __construct(string $token)
    {
        $this->apiUrl = 'https://api.telegram.org/bot' . $token . '/';
        $this->qrCacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'telegram_qr_' . substr(hash('sha256', $token . self::QR_RENDERER_VERSION), 0, 24) . '.json';
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
        try {
            return $this->request('editMessageText', array_merge(
                $this->messagePayload($chatId, $text, $keyboard),
                ['message_id' => $messageId]
            ));
        } catch (RuntimeException $error) {
            $message = strtolower($error->getMessage());
            if (str_contains($message, 'message is not modified')) {
                return ['ok' => true, 'result' => ['message_id' => $messageId]];
            }
            $canFallback = str_contains($message, 'there is no text in the message to edit')
                || str_contains($message, 'message to edit not found')
                || str_contains($message, "message can't be edited");
            if (!$canFallback) {
                throw $error;
            }
        }

        $result = $this->sendMessage($chatId, $text, $keyboard);
        $this->deleteQuietly($chatId, $messageId);
        return $result;
    }

    public function editMessageOrSend(int|string $chatId, int $messageId, string $text, array $keyboard = []): array
    {
        try {
            return $this->request('editMessageText', array_merge(
                $this->messagePayload($chatId, $text, $keyboard),
                ['message_id' => $messageId]
            ));
        } catch (RuntimeException $error) {
            $message = strtolower($error->getMessage());
            if (str_contains($message, 'message is not modified')) {
                return ['ok' => true, 'result' => ['message_id' => $messageId]];
            }
            $canFallback = str_contains($message, 'there is no text in the message to edit')
                || str_contains($message, 'message to edit not found')
                || str_contains($message, "message can't be edited");
            if (!$canFallback) throw $error;
        }
        return $this->sendMessage($chatId, $text, $keyboard);
    }

    public function deleteMessage(int|string $chatId, int $messageId): array
    {
        return $this->request('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
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

        $parts = parse_url($photo);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'img.vietqr.io') {
            return $this->request('sendPhoto', $payload);
        }

        $startedAt = microtime(true);
        $reference = substr(hash('sha256', $photo), 0, 12);
        $cacheKey = hash('sha256', self::QR_RENDERER_VERSION . "\0" . $photo);
        $cachedFileId = $this->cachedQrFileId($cacheKey);
        if ($cachedFileId !== null) {
            try {
                $payload['photo'] = $cachedFileId;
                $result = $this->request('sendPhoto', $payload);
                error_log(sprintf('QR cache hit [ref=%s total_ms=%d]', $reference, $this->elapsedMs($startedAt)));
                return $result;
            } catch (RuntimeException $error) {
                $this->forgetCachedQrFileId($cacheKey);
                error_log(sprintf('QR cache invalid [ref=%s]: %s', $reference, $error->getMessage()));
                $payload['photo'] = $photo;
            }
        }

        $downloadStartedAt = microtime(true);
        $image = $this->downloadImage($photo, $reference);
        $downloadMs = $this->elapsedMs($downloadStartedAt);
        try {
            $payload['photo'] = new CURLFile($image['path'], $image['mime'], $image['name']);
            $uploadStartedAt = microtime(true);
            $result = $this->request('sendPhoto', $payload);
            $uploadMs = $this->elapsedMs($uploadStartedAt);
            $fileId = $this->photoFileId($result);
            if ($fileId !== null) {
                $this->cacheQrFileId($cacheKey, $fileId);
            }
            error_log(sprintf(
                'QR upload succeeded [ref=%s mime=%s size=%d download_ms=%d upload_ms=%d total_ms=%d]',
                $reference,
                $image['mime'],
                $image['size'],
                $downloadMs,
                $uploadMs,
                $this->elapsedMs($startedAt)
            ));
            return $result;
        } catch (Throwable $error) {
            error_log(sprintf(
                'QR upload failed [ref=%s mime=%s size=%d total_ms=%d]: %s',
                $reference,
                $image['mime'],
                $image['size'],
                $this->elapsedMs($startedAt),
                $error->getMessage()
            ));
            throw $error;
        } finally {
            @unlink($image['path']);
        }
    }

    public function replaceWithPhoto(int|string $chatId, int $messageId, string $photo, string $caption, array $keyboard = []): array
    {
        $result = $this->sendPhoto($chatId, $photo, $caption, $keyboard);
        $this->deleteQuietly($chatId, $messageId);
        return $result;
    }

    public function answerCallback(string $callbackId): array
    {
        return $this->request('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->request('setWebhook', ['url' => $url, 'secret_token' => $secret]);
    }

    private function deleteQuietly(int|string $chatId, int $messageId): void
    {
        try {
            $this->request('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        } catch (Throwable $error) {
            error_log('Telegram old message cleanup failed: ' . $error::class);
        }
    }

    private function messagePayload(int|string $chatId, string $text, array $keyboard): array
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($keyboard !== []) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }
        return $payload;
    }

    private function downloadImage(string $url, string $reference): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'img.vietqr.io') {
            throw new RuntimeException('QR image host is not allowed.');
        }

        $path = (string) ($parts['path'] ?? '');
        $svgPath = preg_replace('/\.(?:jpe?g|png)$/i', '.svg', $path, 1, $replacements);
        if ($svgPath === null || $replacements !== 1) {
            throw new RuntimeException('QR image path is not supported.');
        }
        $parts['path'] = $svgPath;
        $downloadUrl = 'https://img.vietqr.io' . $svgPath;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $downloadUrl .= '?' . $parts['query'];
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
        $curl = curl_init($downloadUrl);
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
        $redirectUrl = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        $curlError = curl_error($curl);
        $curlErrorNumber = curl_errno($curl);
        curl_close($curl);
        fclose($handle);

        if ($success === false || $status !== 200 || $size === 0 || $size > 5 * 1024 * 1024
            || !str_starts_with($contentType, 'image/svg+xml')) {
            error_log(sprintf(
                'QR download failed [ref=%s http=%d curl=%d mime=%s size=%d redirect=%s]: %s',
                $reference,
                $status,
                $curlErrorNumber,
                $contentType !== '' ? $contentType : 'none',
                $size,
                $redirectUrl !== '' ? 'yes' : 'no',
                $curlError !== '' ? $curlError : 'invalid response'
            ));
            @unlink($file);
            throw new RuntimeException('Unable to download a valid QR image.');
        }

        $svg = file_get_contents($file);
        if ($svg === false) {
            @unlink($file);
            throw new RuntimeException('Unable to read the QR SVG image.');
        }

        try {
            $this->renderVietQrPng($svg, $file);
        } catch (Throwable $error) {
            @unlink($file);
            throw $error;
        }

        $size = (int) filesize($file);
        $imageInfo = @getimagesize($file);
        if ($size === 0 || $size > 5 * 1024 * 1024 || $imageInfo === false
            || ($imageInfo['mime'] ?? '') !== 'image/png') {
            @unlink($file);
            throw new RuntimeException('Rendered QR image is not a valid PNG image.');
        }

        $mime = 'image/png';
        $extension = 'png';

        error_log(sprintf(
            'QR download succeeded [ref=%s http=%d mime=%s size=%d]',
            $reference,
            $status,
            $mime,
            $size
        ));

        return [
            'path' => $file,
            'mime' => $mime,
            'name' => 'vietqr.' . $extension,
            'size' => $size,
        ];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function photoFileId(array $result): ?string
    {
        $photos = $result['result']['photo'] ?? null;
        if (!is_array($photos) || $photos === []) {
            return null;
        }
        $photo = end($photos);
        return is_array($photo) && isset($photo['file_id']) ? (string) $photo['file_id'] : null;
    }

    private function cachedQrFileId(string $key): ?string
    {
        $cache = $this->readQrCache();
        $entry = $cache[$key] ?? null;
        if (!is_array($entry) || !isset($entry['file_id'], $entry['created_at'])
            || (int) $entry['created_at'] < time() - self::QR_CACHE_TTL) {
            return null;
        }
        return (string) $entry['file_id'];
    }

    private function cacheQrFileId(string $key, string $fileId): void
    {
        $cache = $this->readQrCache();
        $minimumCreatedAt = time() - self::QR_CACHE_TTL;
        $cache = array_filter($cache, static fn ($entry): bool => is_array($entry)
            && (int) ($entry['created_at'] ?? 0) >= $minimumCreatedAt);
        $cache[$key] = ['file_id' => $fileId, 'created_at' => time()];
        if (count($cache) > 500) {
            uasort($cache, static fn (array $a, array $b): int => (int) $b['created_at'] <=> (int) $a['created_at']);
            $cache = array_slice($cache, 0, 500, true);
        }
        $this->writeQrCache($cache);
    }

    private function forgetCachedQrFileId(string $key): void
    {
        $cache = $this->readQrCache();
        unset($cache[$key]);
        $this->writeQrCache($cache);
    }

    private function readQrCache(): array
    {
        $json = @file_get_contents($this->qrCacheFile);
        if ($json === false) {
            return [];
        }
        $cache = json_decode($json, true);
        return is_array($cache) ? $cache : [];
    }

    private function writeQrCache(array $cache): void
    {
        $json = json_encode($cache, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        @file_put_contents($this->qrCacheFile, $json, LOCK_EX);
        @chmod($this->qrCacheFile, 0600);
    }

    private function renderVietQrPng(string $svg, string $file): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            throw new RuntimeException('PHP GD is required to render the QR image.');
        }

        if (preg_match(
            '/href=["\']data:image\/png;base64,([A-Za-z0-9+\/=]+)["\']/i',
            $svg,
            $backgroundMatch
        ) !== 1 || preg_match(
            '/<g\s+transform=["\']translate\(([0-9.]+)\s+([0-9.]+)\)\s+scale\(([0-9.]+)\)["\'][^>]*>.*?<path\s+fill=["\']#111111["\']\s+d=["\']([^"\']+)["\']/s',
            $svg,
            $qrMatch
        ) !== 1) {
            throw new RuntimeException('QR SVG structure is not supported.');
        }

        $background = base64_decode($backgroundMatch[1], true);
        if ($background === false || $background === '' || strlen($background) > 5 * 1024 * 1024) {
            throw new RuntimeException('QR SVG background is invalid.');
        }

        $canvas = @imagecreatefromstring($background);
        if ($canvas === false || imagesx($canvas) !== 540 || imagesy($canvas) !== 540) {
            if ($canvas !== false) {
                imagedestroy($canvas);
            }
            throw new RuntimeException('QR SVG background dimensions are invalid.');
        }

        $offsetX = (float) $qrMatch[1];
        $offsetY = (float) $qrMatch[2];
        $scale = (float) $qrMatch[3];
        $path = $qrMatch[4];
        if ($offsetX < 0 || $offsetY < 0 || $scale <= 0 || $scale > 20
            || preg_match_all('/M(\d+) (\d+)h(\d+)v1h-(\d+)z/', $path, $rectangles, PREG_SET_ORDER) < 1) {
            imagedestroy($canvas);
            throw new RuntimeException('QR SVG path is invalid.');
        }

        $parsedPath = '';
        $black = imagecolorallocate($canvas, 17, 17, 17);
        foreach ($rectangles as $rectangle) {
            $parsedPath .= $rectangle[0];
            $x = (int) $rectangle[1];
            $y = (int) $rectangle[2];
            $width = (int) $rectangle[3];
            if ($width !== (int) $rectangle[4] || $x < 0 || $y < 0 || $width < 1
                || $x + $width > 57 || $y >= 57) {
                imagedestroy($canvas);
                throw new RuntimeException('QR SVG module is outside the expected grid.');
            }

            $left = (int) floor($offsetX + ($x * $scale));
            $top = (int) floor($offsetY + ($y * $scale));
            $right = (int) ceil($offsetX + (($x + $width) * $scale)) - 1;
            $bottom = (int) ceil($offsetY + (($y + 1) * $scale)) - 1;
            imagefilledrectangle($canvas, $left, $top, $right, $bottom, $black);
        }

        if ($parsedPath !== $path || !imagepng($canvas, $file, 6)) {
            imagedestroy($canvas);
            throw new RuntimeException('Unable to render the QR PNG image.');
        }
        imagedestroy($canvas);
    }

    private function request(string $method, array $payload): array
    {
        $hasUpload = false;
        foreach ($payload as $value) {
            if ($value instanceof CURLFile) {
                $hasUpload = true;
                break;
            }
        }

        curl_reset($this->curl);
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->apiUrl . $method,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $hasUpload ? 30 : 10,
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
