<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class TestDatabaseGuard
{
    public static function assertSafeConfig(DatabaseConfig $config): void
    {
        if (!str_ends_with(strtolower($config->name), '_test')) {
            throw new RuntimeException('Refusing test operation: TEST_DB_NAME must end with _test.');
        }

        $applicationDatabase = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? getenv('DB_NAME');
        if (is_string($applicationDatabase) && $applicationDatabase !== '' && $applicationDatabase === $config->name) {
            throw new RuntimeException('Refusing test operation: TEST_DB_NAME matches DB_NAME.');
        }
    }

    public static function assertConnectedDatabase(PDO $pdo, DatabaseConfig $config): void
    {
        $actual = $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($actual !== $config->name) {
            throw new RuntimeException(sprintf(
                'Refusing test operation: connected to %s instead of %s.',
                var_export($actual, true),
                $config->name,
            ));
        }
    }
}
