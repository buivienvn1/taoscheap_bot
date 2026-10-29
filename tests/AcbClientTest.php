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

    fwrite(STDOUT, "AcbClientTest passed.\n");
} finally {
    if (is_file($sessionFile)) {
        unlink($sessionFile);
    }
    if (is_dir($temporaryDirectory)) {
        rmdir($temporaryDirectory);
    }
}
