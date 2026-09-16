<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\MigrationRunner;
use App\Database\TestDatabaseGuard;
use App\Import\Database\CadastralImportService;
use App\Import\Database\CadastralWriteRepository;
use App\Import\Database\ImportRunRepository;
use App\Import\Download\CurlDownloadTransport;
use App\Import\Download\DownloadManager;
use App\Import\Gml\GmlStreamParser;
use App\Import\Scope\CadastralScope;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();

function realImportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clearRealImportSmokeDatabase(PDO $pdo): void
{
    $pdo->exec('DELETE FROM parcel');
    $pdo->exec('DELETE FROM cadastral_territory');
    $pdo->exec('DELETE FROM import_territory');
    $pdo->exec('DELETE FROM active_dataset');
    $pdo->exec('DELETE FROM dataset');
}

$config = DatabaseConfig::fromEnvironment('TEST_DB_');
TestDatabaseGuard::assertSafeConfig($config);
$pdo = ConnectionFactory::create($config);
TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
realImportAssert(str_starts_with($version, '8.4.'), sprintf('Real importer smoke requires MySQL 8.4; got %s.', $version));
(new MigrationRunner($pdo, $root . '/database/migrations'))->migrate();
clearRealImportSmokeDatabase($pdo);

$kuCode = '601101';
$runDirectory = sys_get_temp_dir() . '/viagem-db-import-smoke-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
$downloadDirectory = $runDirectory . '/downloads';
if (!mkdir($downloadDirectory, 0700, true) && !is_dir($downloadDirectory)) {
    throw new RuntimeException('Cannot create DB importer smoke directory.');
}
$zipPath = $downloadDirectory . '/' . $kuCode . '.zip';
$completed = false;

try {
    $scope = CadastralScope::load('jicin', $root);
    $runs = new ImportRunRepository($pdo);
    $datasetId = $runs->create($scope);
    $activeBefore = $pdo->query('SELECT dataset_id FROM active_dataset WHERE slot = 1')->fetchColumn();

    $service = new CadastralImportService(
        $pdo,
        $runs,
        new CadastralWriteRepository($pdo),
        new DownloadManager(new CurlDownloadTransport()),
        new GmlStreamParser(),
    );
    $startedAt = microtime(true);
    $result = $service->importTerritory($datasetId, $scope, $kuCode, $zipPath);
    $durationSeconds = microtime(true) - $startedAt;

    $dataset = $pdo->query("SELECT status, territory_count, parcel_count FROM dataset WHERE id = {$datasetId}")->fetch();
    $checkpoint = $pdo->query("SELECT status, attempt_count, parcel_count, source_checksum, source_size_bytes, retrieved_at, error_code FROM import_territory WHERE dataset_id = {$datasetId} AND ku_code = '{$kuCode}'")->fetch();
    $domain = $pdo->query(<<<SQL
        SELECT
            (SELECT COUNT(*) FROM cadastral_territory WHERE dataset_id = {$datasetId}) AS territory_rows,
            (SELECT COUNT(*) FROM parcel WHERE dataset_id = {$datasetId}) AS parcel_rows,
            (SELECT COUNT(*) FROM cadastral_territory WHERE dataset_id = {$datasetId} AND ST_SRID(geom_native) = 5514 AND ST_IsValid(geom_native) = 1 AND ST_IsEmpty(geom_native) = 0) AS valid_territories,
            (SELECT COUNT(*) FROM parcel WHERE dataset_id = {$datasetId} AND ST_SRID(geom_native) = 5514 AND ST_IsValid(geom_native) = 1 AND ST_IsEmpty(geom_native) = 0) AS valid_parcels,
            (SELECT COALESCE(SUM(ST_NumInteriorRing(ST_GeometryN(geom_native, 1))), 0) FROM parcel WHERE dataset_id = {$datasetId}) AS parcel_holes,
            (SELECT COALESCE(SUM(ST_NumGeometries(geom_native) > 1), 0) FROM parcel WHERE dataset_id = {$datasetId}) AS multi_surface_parcels
        SQL)->fetch();
    $activeAfter = $pdo->query('SELECT dataset_id FROM active_dataset WHERE slot = 1')->fetchColumn();

    realImportAssert($dataset['status'] === 'importing', 'Single-KÚ smoke dataset must remain importing.');
    realImportAssert((int) $dataset['territory_count'] === 1, 'Real dataset territory count differs.');
    realImportAssert((int) $dataset['parcel_count'] === $result->parcelCount, 'Real dataset parcel count differs from parser output.');
    realImportAssert($checkpoint['status'] === 'imported', 'Real checkpoint was not imported.');
    realImportAssert((int) $checkpoint['parcel_count'] === $result->parcelCount, 'Real checkpoint count differs from parser output.');
    realImportAssert($checkpoint['error_code'] === null, 'Real checkpoint contains an error.');
    realImportAssert((int) $domain['territory_rows'] === 1 && (int) $domain['valid_territories'] === 1, 'Real territory geometry validation failed.');
    realImportAssert((int) $domain['parcel_rows'] === $result->parcelCount, 'Real parcel row count differs from parser output.');
    realImportAssert((int) $domain['valid_parcels'] === $result->parcelCount, 'A real parcel geometry is empty, invalid or has the wrong SRID.');
    realImportAssert($activeBefore === false && $activeAfter === false, 'Staging smoke unexpectedly changed active_dataset.');

    echo json_encode([
        'mysql_version' => $version,
        'dataset_id' => $datasetId,
        'dataset_status' => $dataset['status'],
        'dataset_territory_count' => (int) $dataset['territory_count'],
        'dataset_parcel_count' => (int) $dataset['parcel_count'],
        'checkpoint' => [
            'status' => $checkpoint['status'],
            'attempt_count' => (int) $checkpoint['attempt_count'],
            'parcel_count' => (int) $checkpoint['parcel_count'],
            'source_checksum' => $checkpoint['source_checksum'],
            'source_size_bytes' => (int) $checkpoint['source_size_bytes'],
            'retrieved_at' => $checkpoint['retrieved_at'],
        ],
        'geometry' => [
            'valid_territories_srid_5514' => (int) $domain['valid_territories'],
            'valid_parcels_srid_5514' => (int) $domain['valid_parcels'],
            'parcel_holes' => (int) $domain['parcel_holes'],
            'multi_surface_parcels' => (int) $domain['multi_surface_parcels'],
        ],
        'active_dataset_before' => $activeBefore,
        'active_dataset_after' => $activeAfter,
        'duration_seconds' => round($durationSeconds, 3),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    $completed = true;
} finally {
    if ($completed) {
        clearRealImportSmokeDatabase($pdo);
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
        if (is_dir($downloadDirectory)) {
            rmdir($downloadDirectory);
        }
        if (is_dir($runDirectory)) {
            rmdir($runDirectory);
        }
    } else {
        fwrite(STDERR, sprintf("Failed DB smoke state retained: dataset/database plus %s\n", $runDirectory));
    }
}
