<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\TestDatabaseGuard;
use App\Geo\CadastralCrs;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$arguments = array_slice($argv, 1);
$testMode = $arguments === ['--test'];
if ($arguments !== [] && !$testMode) {
    fwrite(STDERR, "Usage: php bin/verify-spatial-reference.php [--test]\n");
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
    CadastralCrs::verify($pdo);
    printf(
        "Application SRS %d verified (SHA-256 %s).\n",
        CadastralCrs::TRANSFORM_SRID,
        CadastralCrs::definitionChecksum(),
    );
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Spatial reference verification failed: %s\n", $exception->getMessage()));
    exit(1);
}
