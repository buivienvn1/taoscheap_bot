<?php

declare(strict_types=1);

final class MigrationRunner
{
    private const BASELINE_FILES = [
        '001_init_schema.sql',
        '002_update_welcome_ui.sql',
        '003_add_multilingual_settings.sql',
        '004_product_quantity_checkout.sql',
    ];

    public function __construct(private Database $db, private string $directory)
    {
    }

    public function status(): array
    {
        $this->ensureHistoryTable();
        $this->baselineCurrentSchema();
        $applied = $this->appliedNames();
        $files = $this->files();
        $pending = array_values(array_filter($files, static fn (string $file): bool => !isset($applied[$file])));

        return [
            'applied' => array_keys($applied),
            'pending' => $pending,
            'total' => count($files),
        ];
    }

    public function run(): array
    {
        $status = $this->status();
        $executed = [];

        foreach ($status['pending'] as $file) {
            if (in_array($file, self::BASELINE_FILES, true)) {
                throw new RuntimeException('Database cũ chưa đạt schema 004; hãy chạy migration cũ thủ công trước.');
            }

            $path = $this->directory . DIRECTORY_SEPARATOR . $file;
            $sql = file_get_contents($path);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Không thể đọc migration: ' . $file);
            }
            if (preg_match('/^\s*DELIMITER\b/im', $sql) === 1) {
                throw new RuntimeException('Migration tự động không hỗ trợ DELIMITER: ' . $file);
            }

            $checksum = hash('sha256', $sql);
            $this->db->transaction(function (Database $db) use ($file, $sql, $checksum): void {
                $db->pdo()->exec($sql);
                $db->execute(
                    'INSERT INTO schema_migrations(migration_name,checksum) VALUES(?,?)',
                    [$file, $checksum]
                );
            });
            $executed[] = $file;
        }

        return $executed;
    }

    private function ensureHistoryTable(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration_name VARCHAR(190) NOT NULL,
                checksum CHAR(64) NULL,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_schema_migrations_name (migration_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function baselineCurrentSchema(): void
    {
        $applied = $this->appliedNames();
        $missingBaseline = array_values(array_filter(
            self::BASELINE_FILES,
            static fn (string $file): bool => !isset($applied[$file])
        ));
        if ($missingBaseline === []) {
            return;
        }

        $currentSchema = $this->db->fetch(
            "SELECT COUNT(*) matched
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE()
               AND ((TABLE_NAME='products' AND COLUMN_NAME='source_variant_id')
                 OR (TABLE_NAME='orders' AND COLUMN_NAME='quantity')
                 OR (TABLE_NAME='orders' AND COLUMN_NAME='wallet_credited')
                 OR (TABLE_NAME='payment_events' AND COLUMN_NAME='wallet_amount'))"
        );
        $requiredTables = $this->db->fetch(
            "SELECT COUNT(*) matched
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_NAME IN ('order_deliveries','purchase_requests','checkout_selections','notification_outbox')"
        );
        if ((int) ($currentSchema['matched'] ?? 0) !== 4 || (int) ($requiredTables['matched'] ?? 0) !== 4) {
            return;
        }

        foreach ($missingBaseline as $file) {
            $path = $this->directory . DIRECTORY_SEPARATOR . $file;
            $checksum = is_file($path) ? hash_file('sha256', $path) : null;
            $this->db->execute(
                'INSERT IGNORE INTO schema_migrations(migration_name,checksum) VALUES(?,?)',
                [$file, $checksum ?: null]
            );
        }
    }

    private function appliedNames(): array
    {
        $rows = $this->db->fetchAll('SELECT migration_name FROM schema_migrations ORDER BY migration_name');
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['migration_name']] = true;
        }
        return $result;
    }

    private function files(): array
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names, SORT_NATURAL);
        return $names;
    }
}
