<?php

declare(strict_types=1);

function adminLoggedIn(): bool
{
    return ($_SESSION['admin'] ?? false) === true;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function verifyCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Phiên làm việc không hợp lệ.');
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectAdmin(string $page, string $message = ''): never
{
    $url = 'index.php?page=' . rawurlencode($page);
    if ($message !== '') $url .= '&message=' . rawurlencode($message);
    header('Location: ' . $url);
    exit;
}
