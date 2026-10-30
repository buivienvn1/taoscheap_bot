<?php

declare(strict_types=1);

abstract class BaseApiSupplier implements SupplierInterface
{
    public function __construct(protected array $config)
    {
    }

    public function name(): string
    {
        return (string) ($this->config['name'] ?? $this->key());
    }

    public function isConfigured(): bool
    {
        return $this->hasRequiredConfig($this->requiredConfigKeys());
    }

    /** @return list<string> */
    abstract protected function requiredConfigKeys(): array;

    protected function hasRequiredConfig(array $requiredKeys): bool
    {
        if (!($this->config['enabled'] ?? false)) {
            return false;
        }
        foreach ($requiredKeys as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, data: array<string, mixed>, raw: string}
     */
    protected function request(
        string $method,
        string $pathWithQuery,
        string $rawBody = '',
        array $headers = []
    ): array {
        $url = rtrim((string) $this->config['base_url'], '/') . '/' . ltrim($pathWithQuery, '/');
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('supplier_curl_init_failed');
        }

        $requestHeaders = ['Accept: application/json'];
        if ($rawBody !== '') {
            $requestHeaders[] = 'Content-Type: application/json';
        }
        $requestHeaders = array_merge($requestHeaders, $headers);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => $rawBody === '' ? null : $rawBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, (int) ($this->config['connect_timeout'] ?? 5)),
            CURLOPT_TIMEOUT => max(1, (int) ($this->config['timeout'] ?? 30)),
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errorCode = curl_errno($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('supplier_transport_error:' . $errorCode . ':' . $error);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('supplier_invalid_json:' . $status);
        }
        return ['status' => $status, 'data' => $data, 'raw' => $body];
    }

    /** @return array{success: false, error_code: string, error: string, retryable: bool, raw: array<string, mixed>} */
    protected function failure(string $code, string $message, bool $retryable, array $raw = []): array
    {
        return [
            'success' => false,
            'error_code' => $code,
            'error' => $message,
            'retryable' => $retryable,
            'raw' => $raw,
        ];
    }

    protected function encodeJson(array $payload): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('supplier_json_encode_failed');
        }
        return $body;
    }
}
