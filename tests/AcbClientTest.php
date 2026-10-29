<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/AcbHttpTransport.php';
require dirname(__DIR__) . '/core/AcbClient.php';

final class FakeAcbHttpTransport implements AcbHttpTransport
{
    /** @var list<array{status:int,body:string}> */
    private array $responses;

    /** @var list<array{method:string,url:string,headers:array,body:?string}> */
    public array $requests = [];

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeout,
        int $requestTimeout,
    ): array {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $response = array_shift($this->responses);
        if (!is_array($response)) {
            throw new RuntimeException('Fake response queue is empty');
        }
        return $response;
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function jsonResponse(array $payload, int $status = 200): array
{
    return [
        'status' => $status,
        'body' => json_encode($payload, JSON_THROW_ON_ERROR),
    ];
}

function containsHeader(array $headers, string $expected): bool
{
    foreach ($headers as $header) {
        if ($header === $expected) {
            return true;
        }
    }
    return false;
}

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'acb-client-test-' . bin2hex(random_bytes(4));
$sessionFile = $temporaryDirectory . DIRECTORY_SEPARATOR . 'session.json';

try {
    $transport = new FakeAcbHttpTransport([
        jsonResponse(['accessToken' => 'access-1', 'refreshToken' => 'refresh-1']),
        jsonResponse([
            'data' => [
                'transactions' => [['transactionNumber' => 'TX-1']],
                'balance' => 100000,
                'totalElements' => 1,
            ],
        ]),
    ]);
    $client = new AcbClient('test-user', 'test-password', $sessionFile, 3, 7, $transport);
    $page = $client->transactionPage(
        '123456789',
        new DateTimeImmutable('2026-01-01 00:00:00+07:00'),
        new DateTimeImmutable('2026-01-01 23:59:59+07:00'),
        2,
        50,
    );

    expect(count($transport->requests) === 2, 'Login and history requests were expected');
    $loginPayload = json_decode((string) $transport->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
    expect($loginPayload['username'] === 'test-user', 'Login username mismatch');
    expect($loginPayload['deviceId'] !== '', 'Device ID must be generated');
    expect(count($transport->requests[0]['headers']) === 6, 'Login headers must match the Python client');
    expect(containsHeader($transport->requests[0]['headers'], 'Accept: application/json, text/plain, */*'), 'Accept header mismatch');
    expect(containsHeader($transport->requests[0]['headers'], 'Content-Type: application/json'), 'Content-Type header mismatch');
    expect(containsHeader($transport->requests[0]['headers'], 'apikey: CQk6S5usauGmMgMYLGqCuDtgtqIM8FI1'), 'API key header mismatch');
    expect(containsHeader($transport->requests[0]['headers'], 'User-Agent: ACB-MBA/8 CFNetwork/1335.0.3 Darwin/21.6.0'), 'User-Agent header mismatch');
    expect(containsHeader($transport->requests[0]['headers'], 'x-app-version: 3.26.0'), 'App version header mismatch');
    expect(containsHeader($transport->requests[0]['headers'], 'x-conversation-id: f43472f1-6d88-4228-91b9-4618a079342a'), 'Conversation header mismatch');
    expect(containsHeader($transport->requests[1]['headers'], 'Authorization: Bearer access-1'), 'Access token header missing');
    expect(str_contains($transport->requests[1]['url'], 'page=2'), 'History page was not forwarded');
    expect(str_contains($transport->requests[1]['url'], 'size=50'), 'History page size was not forwarded');
    expect($page['total'] === 1, 'History total was not normalized');
    expect(is_file($sessionFile), 'Session file was not persisted');

    $transport401 = new FakeAcbHttpTransport([
        jsonResponse(['data' => []], 401),
        jsonResponse(['accessToken' => 'access-2', 'refreshToken' => 'refresh-2']),
        jsonResponse(['data' => []]),
    ]);
    $client401 = new AcbClient('test-user', 'test-password', $sessionFile, 3, 7, $transport401);
    $result401 = $client401->transactionPage(
        '123456789',
        new DateTimeImmutable('2026-01-02 00:00:00+07:00'),
        new DateTimeImmutable('2026-01-02 23:59:59+07:00'),
    );

    expect(count($transport401->requests) === 3, 'A 401 must trigger exactly one login and one retry');
    expect(str_contains($transport401->requests[0]['url'], '/tx-history?'), 'Stored access token should be tried first');
    expect(str_ends_with($transport401->requests[1]['url'], '/mb/v2/auth/tokens'), '401 recovery must perform a fresh login');
    expect($result401['transactions'] === [], 'List-shaped history response was not accepted');

    unlink($sessionFile);
    $transportError = new FakeAcbHttpTransport([
        jsonResponse([
            'errorCode' => 'DEVICE_NOT_REGISTERED',
            'message' => 'Account 123456789 token abcdefghijklmnopqrstuvwxyz123456',
            'details' => 'raw-private-details',
        ], 400),
    ]);
    $clientError = new AcbClient('test-user', 'test-password', $sessionFile, 3, 7, $transportError);
    try {
        $clientError->transactionPage(
            '123456789',
            new DateTimeImmutable('2026-01-03 00:00:00+07:00'),
            new DateTimeImmutable('2026-01-03 00:05:00+07:00'),
        );
        throw new RuntimeException('A 400 login response must fail');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'ACB_HTTP_400:auth_login'), 'Safe error context missing');
        expect(str_contains($error->getMessage(), 'code=DEVICE_NOT_REGISTERED'), 'Whitelisted error code missing');
        expect(str_contains($error->getMessage(), '[redacted-number]'), 'Long account-like number was not redacted');
        expect(str_contains($error->getMessage(), '[redacted-value]'), 'Token-like value was not redacted');
        expect(!str_contains($error->getMessage(), 'raw-private-details'), 'Non-whitelisted response field leaked');
        expect(!str_contains($error->getMessage(), '123456789'), 'Account-like number leaked');
        expect(!str_contains($error->getMessage(), 'abcdefghijklmnopqrstuvwxyz123456'), 'Token-like value leaked');
    }

    fwrite(STDOUT, "AcbClientTest passed.\n");
} finally {
    if (is_file($sessionFile)) {
        unlink($sessionFile);
    }
    if (is_dir($temporaryDirectory)) {
        rmdir($temporaryDirectory);
    }
}
