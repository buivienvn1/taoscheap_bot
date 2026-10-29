<?php

declare(strict_types=1);

final class AcbClient
{
    private const BASE_URL = 'https://apiapp.acb.com.vn';
    private const CLIENT_ID = 'iuSuHYVufIUuNIREV0FB9EoLn9kHsDbm';
    private const API_KEY = 'CQk6S5usauGmMgMYLGqCuDtgtqIM8FI1';

    private ?string $accessToken = null;
    private ?string $refreshToken = null;
    private string $deviceId;
    private AcbHttpTransport $transport;

    public function __construct(
        private string $username,
        private string $password,
        private string $sessionFile,
        private int $connectTimeout = 8,
        private int $requestTimeout = 20,
        ?AcbHttpTransport $transport = null,
    ) {
        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('ACB credentials are not configured');
        }
        $this->transport = $transport ?? new CurlAcbHttpTransport();
        $this->loadSession();
    }

    public function transactionPage(
        string $accountNumber,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $page = 1,
        int $size = 100,
    ): array {
        if (!preg_match('/^\d{6,30}$/', $accountNumber)) {
            throw new InvalidArgumentException('Invalid ACB account number');
        }
        if ($from > $to || $page < 1 || $size < 1 || $size > 500) {
            throw new InvalidArgumentException('Invalid ACB history query');
        }

        $query = http_build_query([
            'account' => $accountNumber,
            'transactionType' => 'ALL',
            'from' => $from->getTimestamp() * 1000,
            'to' => $to->getTimestamp() * 1000,
            'min' => 0,
            'max' => '9007199254740991',
            'page' => $page,
            'size' => $size,
        ], '', '&', PHP_QUERY_RFC3986);

        $path = '/mb/legacy/ss/cs/bankservice/saving/' . rawurlencode($accountNumber)
            . '/tx-history?' . $query;
        $response = $this->authenticatedRequest('GET', $path, 'history');
        $data = $response['data'] ?? null;
        if (is_array($data) && array_is_list($data)) {
            return ['transactions' => $data, 'balance' => null, 'page' => $page, 'size' => $size];
        }
        if (!is_array($data)) {
            throw new UnexpectedValueException('ACB history response has no data object');
        }

        $transactions = $data['transactions'] ?? null;
        if (!is_array($transactions)) {
            throw new UnexpectedValueException('ACB history response has no transaction list');
        }

        return [
            'transactions' => $transactions,
            'balance' => $data['balance'] ?? null,
            'page' => $page,
            'size' => $size,
            'total' => $data['total'] ?? $data['totalElements'] ?? null,
        ];
    }

    private function authenticatedRequest(string $method, string $path, string $context): array
    {
        $this->authenticate();
        try {
            return $this->request($method, $path, null, true, null, $context);
        } catch (RuntimeException $error) {
            if (!str_starts_with($error->getMessage(), 'ACB_HTTP_401:')) {
                throw $error;
            }
            $this->accessToken = null;
            $this->refreshToken = null;
            $this->login();
            return $this->request($method, $path, null, true, null, $context . '_retry');
        }
    }

    private function authenticate(): void
    {
        if ($this->accessToken !== null) {
            return;
        }
        if ($this->refreshToken !== null) {
            try {
                $response = $this->request('POST', '/mb/v2/auth/refresh', null, false, $this->refreshToken, 'auth_refresh');
                $accessToken = trim((string) ($response['accessToken'] ?? ''));
                if ($accessToken === '') {
                    throw new UnexpectedValueException('ACB refresh response has no access token');
                }
                $this->accessToken = $accessToken;
                $newRefreshToken = trim((string) ($response['refreshToken'] ?? ''));
                if ($newRefreshToken !== '') {
                    $this->refreshToken = $newRefreshToken;
                }
                $this->saveSession();
                return;
            } catch (Throwable) {
                $this->accessToken = null;
                $this->refreshToken = null;
            }
        }
        $this->login();
    }

    private function login(): void
    {
        $response = $this->request('POST', '/mb/v2/auth/tokens', [
            'username' => $this->username,
            'password' => $this->password,
            'deviceId' => $this->deviceId,
            'clientId' => self::CLIENT_ID,
        ], false, null, 'auth_login');
        $this->accessToken = trim((string) ($response['accessToken'] ?? ''));
        $this->refreshToken = trim((string) ($response['refreshToken'] ?? ''));
        if ($this->accessToken === '' || $this->refreshToken === '') {
            $this->accessToken = null;
            $this->refreshToken = null;
            throw new UnexpectedValueException('ACB login response has incomplete tokens');
        }
        $this->saveSession();
    }

    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        bool $useAccessToken = false,
        ?string $bearerToken = null,
        string $context = 'request',
    ): array {
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json',
            'apikey: ' . self::API_KEY,
            'User-Agent: ACB-MBA/8 CFNetwork/1335.0.3 Darwin/21.6.0',
            'x-app-version: 3.26.0',
            'x-conversation-id: ' . $this->uuid(),
            'x-request-id: ' . $this->uuid(),
        ];
        $token = $bearerToken ?? ($useAccessToken ? $this->accessToken : null);
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $encoded = null;
        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $response = $this->transport->send(
            $method,
            self::BASE_URL . $path,
            $headers,
            $encoded,
            $this->connectTimeout,
            $this->requestTimeout,
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('ACB_HTTP_' . $response['status'] . ':' . $context);
        }

        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('ACB response is not an object');
        }
        return $decoded;
    }

    private function loadSession(): void
    {
        $session = [];
        if (is_file($this->sessionFile)) {
            $decoded = json_decode((string) file_get_contents($this->sessionFile), true);
            if (is_array($decoded)) {
                $session = $decoded;
            }
        }
        $this->deviceId = (string) ($session['device_id'] ?? strtoupper($this->uuid()));
        $this->accessToken = $this->nullableToken($session['access_token'] ?? null);
        $this->refreshToken = $this->nullableToken($session['refresh_token'] ?? null);
    }

    private function saveSession(): void
    {
        $directory = dirname($this->sessionFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ACB session directory');
        }
        $temporary = $this->sessionFile . '.tmp.' . bin2hex(random_bytes(4));
        $encoded = json_encode([
            'device_id' => $this->deviceId,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'updated_at' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write ACB session');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $this->sessionFile)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace ACB session');
        }
    }

    private function nullableToken(mixed $value): ?string
    {
        $token = trim((string) $value);
        return $token === '' ? null : $token;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
