<?php

declare(strict_types=1);

$app = require __DIR__ . '/config/app.php';
$databaseConfig = require __DIR__ . '/config/database.php';
$bank = require __DIR__ . '/config/bank.php';

date_default_timezone_set($app['timezone']);

spl_autoload_register(static function (string $class): void {
    $paths = [
        __DIR__ . '/core/' . $class . '.php',
        __DIR__ . '/bot/' . $class . '.php',
        __DIR__ . '/bot/handlers/' . $class . '.php',
        __DIR__ . '/suppliers/' . $class . '.php',
        __DIR__ . '/suppliers/ApiProviders/' . $class . '.php',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});

$db = new Database($databaseConfig);
$telegram = new Telegram($app['bot_token']);
$vietQr = new VietQR($bank);
