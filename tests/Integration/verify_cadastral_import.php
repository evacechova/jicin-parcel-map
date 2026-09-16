<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\MigrationRunner;
use App\Database\TestDatabaseGuard;
use App\Import\Database\CadastralImportService;
use App\Import\Database\CadastralWriteRepository;
use App\Import\Database\ImportRunRepository;
use App\Import\Download\DownloadAttemptResult;
use App\Import\Download\DownloadManager;
use App\Import\Download\DownloadTransport;
use App\Import\Gml\GmlStreamParser;
use App\Import\ImportException;
use App\Import\Scope\CadastralScope;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();

final class DatabaseFixtureDownloadTransport implements DownloadTransport
{
    public int $calls = 0;

    /** @param list<DownloadAttemptResult> $results */
    public function __construct(
        private readonly array $results,
        private readonly string $zipPayload,
    ) {
    }

    public function fetch(string $url, string $destination): DownloadAttemptResult
    {
        $result = $this->results[$this->calls] ?? throw new RuntimeException('Unexpected fixture download attempt.');
        ++$this->calls;
        $successful = $result->httpStatus !== null && $result->httpStatus >= 200 && $result->httpStatus < 300;
        $payload = $successful ? $this->zipPayload : 'transient fixture failure';
        if (file_put_contents($destination, $payload) === false) {
            throw new RuntimeException('Cannot write fixture download.');
        }

        return $result->httpStatus === null
            ? DownloadAttemptResult::transportFailure($result->curlError ?? CURLE_RECV_ERROR, strlen($payload))
            : DownloadAttemptResult::http($result->httpStatus, $result->retryAfterSeconds, strlen($payload));
    }
}

function importAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function importAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
    }
}

function createImportFixtureZip(string $path, string $xml): void
{
    $zip = new ZipArchive();
    importAssertSame(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE), 'Cannot create DB ZIP fixture.');
    importAssert($zip->addFromString('601101.xml', $xml), 'Cannot add DB XML fixture.');
    importAssert($zip->close(), 'Cannot close DB ZIP fixture.');
}

function clearImportDatabase(PDO $pdo): void
{
    $pdo->exec('DELETE FROM parcel');
    $pdo->exec('DELETE FROM cadastral_territory');
    $pdo->exec('DELETE FROM import_territory');
    $pdo->exec('DELETE FROM active_dataset');
    $pdo->exec('DELETE FROM dataset');
}

function removeImportFixtureDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $item) {
        if ($item->isDir()) {
            removeImportFixtureDirectory($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

function insertReadyActiveDataset(PDO $pdo): array
{
    $pdo->exec(<<<'SQL'
        INSERT INTO dataset (
            country_code, provider, scope_code, native_srid, display_srid,
            source_url, status, territory_count, parcel_count, completed_at
        ) VALUES (
            'CZ', 'CUZK', 'active_fixture', 5514, 4326,
            'https://example.test/active', 'ready', 1, 0, CURRENT_TIMESTAMP(6)
        )
        SQL);
    $datasetId = (int) $pdo->lastInsertId();
    $territory = $pdo->prepare(<<<'SQL'
        INSERT INTO cadastral_territory (
            dataset_id, ku_code, name, inspire_id, geom_native
        ) VALUES (
            :dataset_id, '999999', 'Active fixture', 'CZ.active-fixture',
            ST_GeomFromText(
                'MULTIPOLYGON(((100 100,110 100,110 110,100 110,100 100)))',
                5514,
                'axis-order=srid-defined'
            )
        )
        SQL);
    $territory->execute(['dataset_id' => $datasetId]);
    $territoryId = (int) $pdo->lastInsertId();
    $active = $pdo->prepare(<<<'SQL'
        INSERT INTO active_dataset (slot, dataset_id, activated_at)
        VALUES (1, :dataset_id, CURRENT_TIMESTAMP(6))
        SQL);
    $active->execute(['dataset_id' => $datasetId]);

    return [$datasetId, $territoryId];
}

function makeImportService(
    PDO $pdo,
    ImportRunRepository $runs,
    DownloadTransport $transport,
): CadastralImportService {
    return new CadastralImportService(
        $pdo,
        $runs,
        new CadastralWriteRepository($pdo),
        new DownloadManager($transport, static function (): void {}),
        new GmlStreamParser(),
    );
}

$temporaryDirectory = sys_get_temp_dir() . '/viagem db import fixtures-' . getmypid();
$pdo = null;

try {
    $config = DatabaseConfig::fromEnvironment('TEST_DB_');
    TestDatabaseGuard::assertSafeConfig($config);
    $pdo = ConnectionFactory::create($config);
    TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    importAssert(str_starts_with($version, '8.4.'), sprintf('Importer DB test requires MySQL 8.4; got %s.', $version));

    (new MigrationRunner($pdo, $root . '/database/migrations'))->migrate();
    clearImportDatabase($pdo);
    if (!mkdir($temporaryDirectory, 0700) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('Cannot create DB import fixture directory.');
    }

    $xml = file_get_contents($root . '/tests/Fixtures/Import/cp-valid.xml');
    importAssert(is_string($xml), 'Cannot read the valid CP fixture.');
    $fixtureZip = $temporaryDirectory . '/fixture-source.zip';
    createImportFixtureZip($fixtureZip, $xml);
    $zipPayload = file_get_contents($fixtureZip);
    importAssert(is_string($zipPayload), 'Cannot read the DB ZIP fixture.');

    [$activeDatasetId, $activeTerritoryId] = insertReadyActiveDataset($pdo);
    $scope = CadastralScope::load('jicin', $root);
    $runs = new ImportRunRepository($pdo);

    $successfulDatasetId = $runs->create($scope);
    importAssertSame('importing', $pdo->query("SELECT status FROM dataset WHERE id = {$successfulDatasetId}")->fetchColumn(), 'New dataset status differs.');
    importAssertSame(240, (int) $pdo->query("SELECT COUNT(*) FROM import_territory WHERE dataset_id = {$successfulDatasetId}")->fetchColumn(), 'Checkpoint count differs.');
    importAssertSame(240, (int) $pdo->query("SELECT COUNT(*) FROM import_territory WHERE dataset_id = {$successfulDatasetId} AND status = 'pending'")->fetchColumn(), 'Pending checkpoint count differs.');

    $retryTransport = new DatabaseFixtureDownloadTransport([
        DownloadAttemptResult::http(500, null, 0),
        DownloadAttemptResult::http(200, null, strlen($zipPayload)),
    ], $zipPayload);
    $successService = makeImportService($pdo, $runs, $retryTransport);
    $successTarget = $temporaryDirectory . '/success/601101.zip';
    mkdir(dirname($successTarget), 0700);
    $result = $successService->importTerritory($successfulDatasetId, $scope, '601101', $successTarget);
    importAssertSame(2, $result->parcelCount, 'Imported parcel count differs.');
    importAssertSame(2, $retryTransport->calls, 'Transient download retry count differs.');

    $dataset = $pdo->query("SELECT country_code, provider, scope_code, native_srid, display_srid, source_url, status, territory_count, parcel_count FROM dataset WHERE id = {$successfulDatasetId}")->fetch();
    importAssertSame('CZ', $dataset['country_code'], 'Dataset country code differs.');
    importAssertSame('CUZK', $dataset['provider'], 'Dataset provider differs.');
    importAssertSame('jicin', $dataset['scope_code'], 'Dataset scope code differs.');
    importAssertSame(5514, (int) $dataset['native_srid'], 'Dataset native SRID differs.');
    importAssertSame(4326, (int) $dataset['display_srid'], 'Dataset display SRID differs.');
    importAssertSame($scope->sourceBaseUrl, $dataset['source_url'], 'Dataset source URL differs.');
    importAssertSame('importing', $dataset['status'], 'Partial fixture dataset must remain importing.');
    importAssertSame(1, (int) $dataset['territory_count'], 'Dataset territory count differs.');
    importAssertSame(2, (int) $dataset['parcel_count'], 'Dataset parcel count differs.');
    $checkpoint = $pdo->query("SELECT source_url, status, attempt_count, last_attempt_at, last_http_status, parcel_count, source_checksum, source_size_bytes, retrieved_at FROM import_territory WHERE dataset_id = {$successfulDatasetId} AND ku_code = '601101'")->fetch();
    importAssertSame($scope->downloadUrl('601101'), $checkpoint['source_url'], 'Checkpoint source URL differs.');
    importAssertSame('imported', $checkpoint['status'], 'Successful checkpoint status differs.');
    importAssertSame(2, (int) $checkpoint['attempt_count'], 'Retry must update the existing checkpoint attempt count.');
    importAssert($checkpoint['last_attempt_at'] !== null, 'Checkpoint last-attempt timestamp is missing.');
    importAssertSame(200, (int) $checkpoint['last_http_status'], 'Checkpoint last HTTP status differs.');
    importAssertSame(2, (int) $checkpoint['parcel_count'], 'Checkpoint parcel count differs.');
    importAssertSame(64, strlen((string) $checkpoint['source_checksum']), 'Checkpoint checksum is missing.');
    importAssertSame(filesize($successTarget), (int) $checkpoint['source_size_bytes'], 'Checkpoint source size differs.');
    importAssert($checkpoint['retrieved_at'] !== null, 'Checkpoint retrieval timestamp is missing.');
    importAssertSame(240, (int) $pdo->query("SELECT COUNT(*) FROM import_territory WHERE dataset_id = {$successfulDatasetId}")->fetchColumn(), 'Retry created a duplicate checkpoint.');

    $territory = $pdo->query(<<<SQL
        SELECT id, name, inspire_id, source_valid_from, source_begin_lifespan_version,
               reference_point_native IS NULL AS reference_is_null,
               ST_SRID(reference_point_native) AS reference_srid,
               ST_SRID(geom_native) AS srid,
               ST_NumGeometries(geom_native) AS polygon_count,
               ST_IsValid(geom_native) AS is_valid
        FROM cadastral_territory
        WHERE dataset_id = {$successfulDatasetId} AND ku_code = '601101'
        SQL)->fetch();
    importAssertSame('CZ.601101', $territory['inspire_id'], 'DB must store INSPIRE localId.');
    importAssertSame('Bašnice', $territory['name'], 'Territory label mapping differs.');
    importAssertSame(null, $territory['source_valid_from'], 'Nil territory validFrom must stay null.');
    importAssertSame('2023-02-24 15:00:50.000000', $territory['source_begin_lifespan_version'], 'Territory source lifespan mapping differs.');
    importAssertSame(0, (int) $territory['reference_is_null'], 'Territory reference point is missing.');
    importAssertSame(5514, (int) $territory['reference_srid'], 'Territory reference point SRID differs.');
    importAssertSame(5514, (int) $territory['srid'], 'Territory SRID differs.');
    importAssertSame(2, (int) $territory['polygon_count'], 'Territory MultiSurface did not survive DB insertion.');
    importAssertSame(1, (int) $territory['is_valid'], 'Stored territory geometry is invalid.');

    $parcels = $pdo->query(<<<SQL
        SELECT inspire_id, label, national_cadastral_reference, area_m2,
               source_valid_from, source_begin_lifespan_version,
               ST_SRID(geom_native) AS srid,
               ST_NumGeometries(geom_native) AS polygon_count,
               ST_NumInteriorRing(ST_GeometryN(geom_native, 1)) AS hole_count,
               reference_point_native IS NULL AS reference_is_null,
               ST_IsValid(geom_native) AS is_valid
        FROM parcel
        WHERE dataset_id = {$successfulDatasetId}
        ORDER BY inspire_id
        SQL)->fetchAll();
    importAssertSame(2, count($parcels), 'Stored parcel row count differs.');
    importAssertSame('CP.fixture-1', $parcels[0]['inspire_id'], 'First parcel localId differs.');
    importAssertSame('1/1', $parcels[0]['label'], 'Parcel label mapping differs.');
    importAssertSame('601101-1/1', $parcels[0]['national_cadastral_reference'], 'Parcel national reference mapping differs.');
    importAssertSame('64.25', $parcels[0]['area_m2'], 'Source areaValue mapping differs.');
    importAssertSame(null, $parcels[0]['source_valid_from'], 'Nil parcel validFrom must stay null.');
    importAssertSame('2025-09-10 13:53:34.000000', $parcels[0]['source_begin_lifespan_version'], 'Parcel source lifespan mapping differs.');
    importAssertSame(5514, (int) $parcels[0]['srid'], 'Parcel SRID differs.');
    importAssertSame(1, (int) $parcels[0]['hole_count'], 'Parcel hole did not survive parser-to-DB roundtrip.');
    importAssertSame(2, (int) $parcels[1]['polygon_count'], 'Parcel MultiSurface did not survive parser-to-DB roundtrip.');
    importAssertSame('2020-01-02 03:04:05.000000', $parcels[1]['source_valid_from'], 'Parcel validFrom mapping differs.');
    importAssertSame(null, $parcels[1]['source_begin_lifespan_version'], 'Missing parcel lifespan must stay null.');
    importAssertSame(1, (int) $parcels[1]['reference_is_null'], 'Nullable parcel reference point differs.');
    importAssertSame(1, (int) $parcels[0]['is_valid'], 'Stored parcel geometry is invalid.');
    importAssertSame(1, (int) $parcels[1]['is_valid'], 'Stored MultiSurface parcel geometry is invalid.');

    $failedDatasetId = $runs->create($scope);
    $duplicateXml = str_replace(
        '<base:localId>CP.fixture-2</base:localId>',
        '<base:localId>CP.fixture-1</base:localId>',
        $xml,
    );
    $duplicateZip = $temporaryDirectory . '/duplicate-source.zip';
    createImportFixtureZip($duplicateZip, $duplicateXml);
    $duplicatePayload = file_get_contents($duplicateZip);
    importAssert(is_string($duplicatePayload), 'Cannot read duplicate-ID ZIP fixture.');
    $failureTransport = new DatabaseFixtureDownloadTransport([
        DownloadAttemptResult::http(200, null, strlen($duplicatePayload)),
    ], $duplicatePayload);
    $failureService = makeImportService($pdo, $runs, $failureTransport);
    $failureTarget = $temporaryDirectory . '/failure/601101.zip';
    mkdir(dirname($failureTarget), 0700);
    try {
        $failureService->importTerritory($failedDatasetId, $scope, '601101', $failureTarget);
        throw new RuntimeException('Duplicate parcel fixture unexpectedly imported.');
    } catch (ImportException $exception) {
        importAssertSame('database_error', $exception->errorCode, 'Mid-KÚ DB failure code differs.');
    }

    $failedDataset = $pdo->query("SELECT status, territory_count, parcel_count, completed_at FROM dataset WHERE id = {$failedDatasetId}")->fetch();
    importAssertSame('failed', $failedDataset['status'], 'Failed dataset status differs.');
    importAssertSame(0, (int) $failedDataset['territory_count'], 'Failed dataset territory count was not rolled back.');
    importAssertSame(0, (int) $failedDataset['parcel_count'], 'Failed dataset parcel count was not rolled back.');
    importAssert($failedDataset['completed_at'] !== null, 'Failed dataset completion timestamp is missing.');
    importAssertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM cadastral_territory WHERE dataset_id = {$failedDatasetId}")->fetchColumn(), 'Failed KÚ left a territory row.');
    importAssertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM parcel WHERE dataset_id = {$failedDatasetId}")->fetchColumn(), 'Failed KÚ left parcel rows.');
    $failedCheckpoint = $pdo->query("SELECT status, attempt_count, parcel_count, error_code, error_message, source_checksum FROM import_territory WHERE dataset_id = {$failedDatasetId} AND ku_code = '601101'")->fetch();
    importAssertSame('failed', $failedCheckpoint['status'], 'Failed checkpoint status differs.');
    importAssertSame(1, (int) $failedCheckpoint['attempt_count'], 'Failed checkpoint attempt count differs.');
    importAssertSame(0, (int) $failedCheckpoint['parcel_count'], 'Failed checkpoint parcel count differs.');
    importAssertSame('database_error', $failedCheckpoint['error_code'], 'Failed checkpoint error code differs.');
    importAssertSame('Database operation failed.', $failedCheckpoint['error_message'], 'Failed checkpoint safe message differs.');
    importAssertSame(64, strlen((string) $failedCheckpoint['source_checksum']), 'Failed checkpoint lost downloaded artifact metadata.');

    importAssertSame($activeDatasetId, (int) $pdo->query('SELECT dataset_id FROM active_dataset WHERE slot = 1')->fetchColumn(), 'Failed import changed active_dataset.');
    importAssertSame('ready', $pdo->query("SELECT status FROM dataset WHERE id = {$activeDatasetId}")->fetchColumn(), 'Failed import changed active dataset status.');

    try {
        $crossDataset = $pdo->prepare(<<<'SQL'
            INSERT INTO parcel (
                dataset_id, territory_id, inspire_id, label,
                national_cadastral_reference, area_m2, geom_native
            ) VALUES (
                :dataset_id, :territory_id, 'CP.cross-dataset', 'cross',
                '601101-cross', 1,
                ST_GeomFromText(
                    'MULTIPOLYGON(((0 0,1 0,1 1,0 1,0 0)))',
                    5514,
                    'axis-order=srid-defined'
                )
            )
            SQL);
        $crossDataset->execute(['dataset_id' => $successfulDatasetId, 'territory_id' => $activeTerritoryId]);
        throw new RuntimeException('Cross-dataset parcel relationship was accepted.');
    } catch (PDOException) {
        // Expected composite foreign-key rejection.
    }

    echo "Cadastral DB import integration verification passed.\n";
} finally {
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        clearImportDatabase($pdo);
    }
    removeImportFixtureDirectory($temporaryDirectory);
}
