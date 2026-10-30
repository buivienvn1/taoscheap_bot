<?php

declare(strict_types=1);

// Local-only Telegram stand-in used by the integration test; never calls Telegram.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$statePath = getenv('REFERRAL_MOCK_STATE');
$state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : [];
$method = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$result = [];
if ($method === 'getMe') {
    $result = ['id' => 9999, 'username' => 'referral_test_bot', 'is_bot' => true];
} elseif ($method === 'getChatMember') {
    $status = $state[(string) ($_POST['user_id'] ?? '')] ?? 'member';
    if ($status === 'api_error') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'description' => 'test membership unavailable']);
        exit;
    }
    $result = ['status' => $status];
} else {
    file_put_contents($statePath . '.messages', json_encode(['method' => $method, 'payload' => $_POST], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $result = ['message_id' => (int) ($_POST['message_id'] ?? 100)];
}
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'result' => $result]);
