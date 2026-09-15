<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\MigrationRunner;
use App\Database\TestDatabaseGuard;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$arguments = array_slice($argv, 1);
$testMode = in_array('--test', $arguments, true);
$arguments = array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--test'));
$command = $arguments[0] ?? 'status';

if (count($arguments) > 1 || !in_array($command, ['migrate', 'rollback', 'status'], true)) {
    fwrite(STDERR, "Usage: php bin/database.php [migrate|rollback|status] [--test]\n");
    exit(2);
}

if ($testMode) {
    Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();
}

try {
    $config = DatabaseConfig::fromEnvironment($testMode ? 'TEST_DB_' : 'DB_');
    if ($testMode) {
        TestDatabaseGuard::assertSafeConfig($config);
    }

    $pdo = ConnectionFactory::create($config);
    if ($testMode) {
        TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
    }

    $runner = new MigrationRunner($pdo, $root . '/database/migrations');

    if ($command === 'migrate') {
        $completed = $runner->migrate();
        echo $completed === []
            ? "Database is already up to date.\n"
            : sprintf("Applied: %s\n", implode(', ', $completed));
    } elseif ($command === 'rollback') {
        $rolledBack = $runner->rollback();
        echo $rolledBack === null
            ? "Nothing to roll back.\n"
            : sprintf("Rolled back: %s\n", $rolledBack);
    } else {
        foreach ($runner->status() as $version => $applied) {
            printf("[%s] %s\n", $applied ? 'applied' : 'pending', $version);
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Database command failed: %s\n", $exception->getMessage()));
    exit(1);
}
