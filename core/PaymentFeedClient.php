<?php

declare(strict_types=1);

final class PaymentFeedClient
{
    public function __construct(private array $config)
    {
    }

    public function fetchPage(): array
    {
        $url = (string) ($this->config['url'] ?? '');
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || strcasecmp((string) ($parts['host'] ?? ''), (string) ($this->config['allowed_host'] ?? '')) !== 0) {
            throw new RuntimeException('feed_url_rejected');
        }
        $token = (string) ($this->config['token'] ?? '');
        if ($token === '' || str_contains($token, 'REPLACE_')) throw new RuntimeException('feed_token_missing');
        if (preg_match('/[\r\n]/', $token) === 1) throw new RuntimeException('feed_auth_rejected');

        $authMode = (string) ($this->config['auth_mode'] ?? 'header');
        $headers = ['Accept: application/json'];
        if ($authMode === 'query') {
            $parameter = (string) ($this->config['token_parameter'] ?? 'token');
            if (preg_match('/^[A-Za-z0-9_-]+$/', $parameter) !== 1 || isset($parts['query'])) throw new RuntimeException('feed_auth_rejected');
            $url .= '?' . rawurlencode($parameter) . '=' . rawurlencode($token);
        } elseif ($authMode === 'header') {
            $headerName = (string) ($this->config['token_header'] ?? 'Authorization');
            if (preg_match('/^[A-Za-z0-9-]+$/', $headerName) !== 1) throw new RuntimeException('feed_auth_rejected');
            $headers[] = $headerName . ': ' . (string) ($this->config['token_prefix'] ?? 'Bearer ') . $token;
        } else {
            throw new RuntimeException('feed_auth_rejected');
        }

        $body = '';
        $limit = max(1024, (int) ($this->config['max_response_bytes'] ?? 1048576));
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, (int) ($this->config['timeout_seconds'] ?? 15)),
            CURLOPT_TIMEOUT => (int) ($this->config['timeout_seconds'] ?? 15),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errorCode = curl_errno($curl);
        curl_close($curl);
        if ($ok === false || $errorCode !== 0) throw new RuntimeException('feed_http_transport');
        if ($status === 401 || $status === 403) throw new RuntimeException('feed_auth_failed');
        if ($status === 429) throw new RuntimeException('feed_rate_limited');
        if ($status < 200 || $status >= 300) throw new RuntimeException('feed_http_status');
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('feed_payload_invalid');
        return $decoded;
    }
}
