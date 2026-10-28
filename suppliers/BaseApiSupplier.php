<?php

declare(strict_types=1);

abstract class BaseApiSupplier implements SupplierInterface
{
    public function __construct(protected array $config)
    {
    }

    protected function request(string $method, string $path, array $payload = []): array
    {
        $curl = curl_init(rtrim($this->config['base_url'], '/') . '/' . ltrim($path, '/'));
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if (!empty($this->config['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $this->config['api_key'];
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload === [] ? null : json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Supplier API error: ' . ($error ?: (string) $status));
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Supplier API returned invalid JSON.');
        }
        return $data;
    }
}
