<?php

declare(strict_types=1);

final class CurlAcbHttpTransport implements AcbHttpTransport
{
    private const MAX_RESPONSE_BYTES = 5_000_000;

    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL extension is required');
        }
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeout,
        int $requestTimeout,
    ): array {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        $responseBody = '';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        try {
            $success = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
        } finally {
            curl_close($handle);
        }

        if ($success === false) {
            throw new RuntimeException('ACB_NETWORK_ERROR:' . ($curlError !== '' ? 'curl' : 'response_limit'));
        }

        return ['status' => $status, 'body' => $responseBody];
    }
}
