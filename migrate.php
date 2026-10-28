<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

try {
    $runner = new MigrationRunner($db, __DIR__ . '/migrations');
    $executed = $runner->run();
    if ($executed === []) {
        fwrite(STDOUT, "Database schema is up to date.\n");
    } else {
        foreach ($executed as $migration) {
            fwrite(STDOUT, 'Applied migration: ' . $migration . "\n");
        }
    }
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
