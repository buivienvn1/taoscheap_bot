<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$payment = require dirname(__DIR__) . '/config/payment.php';
$acb = $payment['acb'] ?? [];
$username = (string) ($acb['username'] ?? '');
$password = (string) ($acb['password'] ?? '');
$accountNumber = preg_replace('/\D+/', '', (string) ($acb['account_number'] ?? '')) ?? '';
$timeout = (int) ($acb['request_timeout'] ?? 20);

if ($username === '' || $password === '' || $accountNumber === '') {
    fwrite(STDERR, "PROBE_STATUS=failed\nPROBE_STAGE=configuration\nERROR_CODE=missing_configuration\n");
    exit(1);
}
if (!function_exists('proc_open')) {
    fwrite(STDERR, "PROBE_STATUS=failed\nPROBE_STAGE=configuration\nERROR_CODE=proc_open_unavailable\n");
    exit(1);
}

$python = isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '' ? $argv[1] : 'python3';
$script = __DIR__ . '/acb_python_probe.py';
$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pipes = [];
$process = proc_open([$python, $script], $descriptorSpec, $pipes, dirname(__DIR__), null, [
    'bypass_shell' => true,
]);
if (!is_resource($process)) {
    fwrite(STDERR, "PROBE_STATUS=failed\nPROBE_STAGE=configuration\nERROR_CODE=python_start_failed\n");
    exit(1);
}

$input = json_encode([
    'username' => $username,
    'password' => $password,
    'account_number' => $accountNumber,
    'timeout' => max(1, min(120, $timeout)),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

try {
    if (fwrite($pipes[0], $input) === false) {
        throw new RuntimeException('stdin_write_failed');
    }
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1], 16_384);
    $stderr = stream_get_contents($pipes[2], 16_384);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
} catch (Throwable $error) {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    fwrite(STDERR, "PROBE_STATUS=failed\nPROBE_STAGE=wrapper\nERROR_CODE=" . $error->getMessage() . "\n");
    exit(1);
}

if (is_string($stdout) && $stdout !== '') {
    fwrite(STDOUT, $stdout);
}
if ($exitCode !== 0 && is_string($stderr) && $stderr !== '') {
    fwrite(STDERR, "PYTHON_STDERR_PRESENT=yes\n");
}
exit($exitCode === 0 ? 0 : 1);
