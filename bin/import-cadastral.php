<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\MigrationRunner;
use App\Import\Database\CadastralImportService;
use App\Import\Database\CadastralWriteRepository;
use App\Import\Database\DatasetPublicationService;
use App\Import\Database\DatasetValidator;
use App\Import\Database\FullImportRunner;
use App\Import\Database\ImportPreflight;
use App\Import\Database\ImportRunRepository;
use App\Import\Download\CurlDownloadTransport;
use App\Import\Download\DownloadManager;
use App\Import\Gml\GmlStreamParser;
use App\Import\ImportException;
use App\Import\Scope\CadastralScope;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$scopeCode = null;
$keepArtifacts = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--scope=')) {
        if ($scopeCode !== null) {
            fwrite(STDERR, "Duplicate --scope option.\n");
            exit(2);
        }
        $scopeCode = substr($argument, strlen('--scope='));
    } elseif ($argument === '--keep-artifacts') {
        $keepArtifacts = true;
    } else {
        fwrite(STDERR, "Usage: php bin/import-cadastral.php --scope=jicin [--keep-artifacts]\n");
        exit(2);
    }
}

if ($scopeCode !== 'jicin') {
    fwrite(STDERR, "Usage: php bin/import-cadastral.php --scope=jicin [--keep-artifacts]\n");
    exit(2);
}

try {
    $scope = CadastralScope::load($scopeCode, $root);
    $pdo = ConnectionFactory::create(DatabaseConfig::fromEnvironment());
    $migrationStatus = (new MigrationRunner($pdo, $root . '/database/migrations'))->status();
    if (in_array(false, $migrationStatus, true)) {
        throw new ImportException('pending_migrations', 'Database migrations must be applied before import.');
    }
    (new ImportPreflight($pdo))->verify();

    $runs = new ImportRunRepository($pdo);
    $validator = new DatasetValidator($pdo);
    $importer = new CadastralImportService(
        $pdo,
        $runs,
        new CadastralWriteRepository($pdo),
        new DownloadManager(new CurlDownloadTransport()),
        new GmlStreamParser(),
    );
    $runner = new FullImportRunner(
        $runs,
        $importer,
        new DatasetPublicationService($pdo, $validator),
        $root,
        static fn (string $message) => fwrite(STDOUT, $message . PHP_EOL),
    );
    $result = $runner->run($scope, $keepArtifacts);

    printf(
        "Activated dataset %d: %d KÚ, %d parcels, %d bytes in %.3f s.%s\n",
        $result->datasetId,
        $result->territoryCount,
        $result->parcelCount,
        $result->downloadSizeBytes,
        $result->durationSeconds,
        $result->previousDatasetId === null
            ? ' First activation.'
            : sprintf(' Retired dataset %d.', $result->previousDatasetId),
    );
    if ($result->artifactsRetained) {
        printf("Artifacts retained in %s.\n", $result->runDirectory);
    }
} catch (Throwable $exception) {
    $code = $exception instanceof ImportException ? $exception->errorCode : 'full_import_error';
    $message = $exception instanceof ImportException ? $exception->getMessage() : 'Full cadastral import failed.';
    fwrite(STDERR, sprintf("Import failed [%s]: %s\n", $code, $message));
    exit(1);
}
