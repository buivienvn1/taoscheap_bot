<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $timezone = new DateTimeZone((string) ($config['timezone'] ?? date_default_timezone_get()));
        $offset = $timezone->getOffset(new DateTimeImmutable('now', $timezone));
        $sign = $offset < 0 ? '-' : '+';
        $absoluteOffset = abs($offset);
        $mysqlTimezone = sprintf('%s%02d:%02d', $sign, intdiv($absoluteOffset, 3600), intdiv($absoluteOffset % 3600, 60));
        $this->pdo->exec("SET time_zone = " . $this->pdo->quote($mysqlTimezone));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
