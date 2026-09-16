<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\MigrationRunner;
use App\Database\TestDatabaseGuard;
use App\Import\Database\DatasetPublicationService;
use App\Import\Database\DatasetValidator;
use App\Import\Database\FullImportRunner;
use App\Import\Database\ImportRunRepository;
use App\Import\Database\ImportTerritoryResult;
use App\Import\Database\TerritoryImporter;
use App\Import\ImportException;
use App\Import\Scope\CadastralScope;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();

function publicationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publicationAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
    }
}

function clearPublicationDatabase(PDO $pdo): void
{
    $pdo->exec('DELETE FROM parcel');
    $pdo->exec('DELETE FROM cadastral_territory');
    $pdo->exec('DELETE FROM import_territory');
    $pdo->exec('DELETE FROM active_dataset');
    $pdo->exec('DELETE FROM dataset');
}

function removePublicationDirectory(string $directory, string $storageRoot): void
{
    if (!is_dir($directory)) {
        return;
    }
    $realDirectory = realpath($directory);
    $realRoot = realpath($storageRoot);
    publicationAssert(
        $realDirectory !== false && $realRoot !== false && str_starts_with($realDirectory, $realRoot . DIRECTORY_SEPARATOR),
        'Refusing to remove an unexpected publication fixture directory.',
    );
    foreach (new FilesystemIterator($realDirectory) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            removePublicationDirectory($item->getPathname(), $storageRoot);
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($realDirectory);
}

function seedValidPublicationDataset(PDO $pdo, ImportRunRepository $runs, CadastralScope $scope): int
{
    $datasetId = $runs->create($scope);
    $territory = $pdo->prepare(<<<'SQL'
        INSERT INTO cadastral_territory (dataset_id, ku_code, name, inspire_id, geom_native)
        VALUES (
            :dataset_id, :ku_code, :name, :inspire_id,
            ST_GeomFromText(
                'MULTIPOLYGON(((-672000 -1013100,-671980 -1013100,-671980 -1013080,-672000 -1013080,-672000 -1013100)))',
                5514,
                'axis-order=srid-defined'
            )
        )
        SQL);
    $checkpoint = $pdo->prepare(<<<'SQL'
        UPDATE import_territory
        SET status = 'imported', attempt_count = 1,
            last_attempt_at = CURRENT_TIMESTAMP(6), last_http_status = 200,
            source_checksum = :checksum, source_size_bytes = 100,
            retrieved_at = CURRENT_TIMESTAMP(6), parcel_count = 0
        WHERE dataset_id = :dataset_id AND ku_code = :ku_code
        SQL);

    $pdo->beginTransaction();
    try {
        foreach ($scope->territoryCodes() as $kuCode) {
            $territory->execute([
                'dataset_id' => $datasetId,
                'ku_code' => $kuCode,
                'name' => $scope->territories[$kuCode],
                'inspire_id' => 'CZ.' . $kuCode,
            ]);
            $checkpoint->execute([
                'checksum' => hash('sha256', $kuCode),
                'dataset_id' => $datasetId,
                'ku_code' => $kuCode,
            ]);
        }
        $update = $pdo->prepare('UPDATE dataset SET territory_count = 240 WHERE id = :dataset_id');
        $update->execute(['dataset_id' => $datasetId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return $datasetId;
}

function expectValidationFailure(
    DatasetPublicationService $publication,
    int $datasetId,
    CadastralScope $scope,
    string $message,
): void {
    try {
        $publication->validateAndActivate($datasetId, $scope);
        throw new RuntimeException($message);
    } catch (ImportException $exception) {
        publicationAssertSame('dataset_validation_failed', $exception->errorCode, 'Unexpected validation failure code.');
    }
}

final class FixtureTerritoryImporter implements TerritoryImporter
{
    /** @var list<string> */
    public array $targets = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ImportRunRepository $runs,
        private readonly ?string $skipTerritoryCode = null,
    ) {
    }

    public function importTerritory(
        int $datasetId,
        CadastralScope $scope,
        string $kuCode,
        string $downloadTarget,
    ): ImportTerritoryResult {
        $this->targets[] = $downloadTarget;
        $this->runs->markProcessing($datasetId, $kuCode);
        $this->pdo->beginTransaction();
        try {
            $territoryId = 0;
            if ($kuCode !== $this->skipTerritoryCode) {
                $insert = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO cadastral_territory (dataset_id, ku_code, name, inspire_id, geom_native)
                    VALUES (
                        :dataset_id, :ku_code, :name, :inspire_id,
                        ST_GeomFromText(
                            'MULTIPOLYGON(((-672000 -1013100,-671980 -1013100,-671980 -1013080,-672000 -1013080,-672000 -1013100)))',
                            5514,
                            'axis-order=srid-defined'
                        )
                    )
                    SQL);
                $insert->execute([
                    'dataset_id' => $datasetId,
                    'ku_code' => $kuCode,
                    'name' => $scope->territories[$kuCode],
                    'inspire_id' => 'CZ.' . $kuCode,
                ]);
                $territoryId = (int) $this->pdo->lastInsertId();
                $increment = $this->pdo->prepare(
                    'UPDATE dataset SET territory_count = territory_count + 1 WHERE id = :dataset_id',
                );
                $increment->execute(['dataset_id' => $datasetId]);
            }
            $checkpoint = $this->pdo->prepare(<<<'SQL'
                UPDATE import_territory
                SET status = 'imported', attempt_count = 1,
                    last_attempt_at = CURRENT_TIMESTAMP(6), last_http_status = 200,
                    source_checksum = :checksum, source_size_bytes = 100,
                    retrieved_at = CURRENT_TIMESTAMP(6), parcel_count = 0
                WHERE dataset_id = :dataset_id AND ku_code = :ku_code AND status = 'processing'
                SQL);
            $checksum = hash('sha256', $kuCode);
            $checkpoint->execute([
                'checksum' => $checksum,
                'dataset_id' => $datasetId,
                'ku_code' => $kuCode,
            ]);
            $this->pdo->commit();

            return new ImportTerritoryResult($datasetId, $territoryId, $kuCode, 0, $checksum, 100);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}

$pdo = null;
$fixtureRunDirectories = [];
$storageRoot = $root . '/storage/imports';

try {
    $config = DatabaseConfig::fromEnvironment('TEST_DB_');
    TestDatabaseGuard::assertSafeConfig($config);
    $pdo = ConnectionFactory::create($config);
    TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    publicationAssert(str_starts_with($version, '8.4.'), sprintf('Publication test requires MySQL 8.4; got %s.', $version));
    (new MigrationRunner($pdo, $root . '/database/migrations'))->migrate();
    clearPublicationDatabase($pdo);

    $scope = CadastralScope::load('jicin', $root);
    $runs = new ImportRunRepository($pdo);
    $validator = new DatasetValidator($pdo);
    $publication = new DatasetPublicationService($pdo, $validator);

    $progress = [];
    $firstImporter = new FixtureTerritoryImporter($pdo, $runs);
    $runner = new FullImportRunner(
        $runs,
        $firstImporter,
        $publication,
        $root,
        static function (string $message) use (&$progress): void {
            $progress[] = $message;
        },
    );
    $first = $runner->run($scope);
    publicationAssertSame(240, count($firstImporter->targets), 'Full runner did not process every configured KÚ.');
    publicationAssertSame(240, $first->territoryCount, 'First activation territory count differs.');
    publicationAssertSame(0, $first->parcelCount, 'Fixture full-run parcel count differs.');
    publicationAssertSame(24000, $first->downloadSizeBytes, 'Fixture full-run source size differs.');
    publicationAssertSame(null, $first->previousDatasetId, 'First activation unexpectedly found a predecessor.');
    publicationAssert(!is_dir($first->runDirectory), 'Successful runner did not remove run-specific artifacts.');
    publicationAssertSame('ready', $pdo->query("SELECT status FROM dataset WHERE id = {$first->datasetId}")->fetchColumn(), 'First dataset is not ready.');
    publicationAssertSame($first->datasetId, (int) $pdo->query('SELECT dataset_id FROM active_dataset WHERE slot = 1')->fetchColumn(), 'First active pointer differs.');
    publicationAssertSame('true', $pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(validation_report, '$.valid')) FROM dataset WHERE id = {$first->datasetId}")->fetchColumn(), 'Successful validation report is missing.');
    $downloadDirectories = array_unique(array_map('dirname', $firstImporter->targets));
    publicationAssertSame(1, count($downloadDirectories), 'Full runner used more than one download directory.');

    $secondDatasetId = seedValidPublicationDataset($pdo, $runs, $scope);
    $second = $publication->validateAndActivate($secondDatasetId, $scope);
    publicationAssertSame($first->datasetId, $second->previousDatasetId, 'A -> B activation predecessor differs.');
    publicationAssertSame('retired', $pdo->query("SELECT status FROM dataset WHERE id = {$first->datasetId}")->fetchColumn(), 'Dataset A was not retired.');
    publicationAssertSame('ready', $pdo->query("SELECT status FROM dataset WHERE id = {$secondDatasetId}")->fetchColumn(), 'Dataset B is not ready/active.');
    publicationAssertSame($secondDatasetId, (int) $pdo->query('SELECT dataset_id FROM active_dataset WHERE slot = 1')->fetchColumn(), 'Pointer does not reference only dataset B.');

    $invalidDatasetId = seedValidPublicationDataset($pdo, $runs, $scope);
    $firstCode = $scope->territoryCodes()[0];
    foreach (['pending', 'processing', 'failed'] as $checkpointStatus) {
        $update = $pdo->prepare('UPDATE import_territory SET status = :status WHERE dataset_id = :dataset_id AND ku_code = :ku_code');
        $update->execute(['status' => $checkpointStatus, 'dataset_id' => $invalidDatasetId, 'ku_code' => $firstCode]);
        expectValidationFailure($publication, $invalidDatasetId, $scope, sprintf('%s checkpoint was activated.', $checkpointStatus));
        publicationAssertSame($secondDatasetId, (int) $pdo->query('SELECT dataset_id FROM active_dataset WHERE slot = 1')->fetchColumn(), 'Validation failure changed active pointer.');
        $update->execute(['status' => 'imported', 'dataset_id' => $invalidDatasetId, 'ku_code' => $firstCode]);
    }

    $pdo->exec("UPDATE dataset SET territory_count = 239 WHERE id = {$invalidDatasetId}");
    expectValidationFailure($publication, $invalidDatasetId, $scope, 'Dataset count mismatch was activated.');
    $pdo->exec("UPDATE dataset SET territory_count = 240 WHERE id = {$invalidDatasetId}");

    $delete = $pdo->prepare('DELETE FROM cadastral_territory WHERE dataset_id = :dataset_id AND ku_code = :ku_code');
    $delete->execute(['dataset_id' => $invalidDatasetId, 'ku_code' => $firstCode]);
    $pdo->exec("UPDATE dataset SET territory_count = 239 WHERE id = {$invalidDatasetId}");
    expectValidationFailure($publication, $invalidDatasetId, $scope, 'Dataset with a missing KÚ was activated.');
    publicationAssertSame('importing', $pdo->query("SELECT status FROM dataset WHERE id = {$invalidDatasetId}")->fetchColumn(), 'Rejected candidate state was unexpectedly published.');
    publicationAssertSame('ready', $pdo->query("SELECT status FROM dataset WHERE id = {$secondDatasetId}")->fetchColumn(), 'Validation failure changed active dataset status.');

    $transactionDatasetId = seedValidPublicationDataset($pdo, $runs, $scope);
    $faultingPublication = new DatasetPublicationService(
        $pdo,
        $validator,
        static fn () => throw new RuntimeException('forced activation failure'),
    );
    try {
        $faultingPublication->validateAndActivate($transactionDatasetId, $scope);
        throw new RuntimeException('Forced pointer failure unexpectedly committed.');
    } catch (RuntimeException $exception) {
        publicationAssert(str_contains($exception->getMessage(), 'forced activation failure'), 'Unexpected transaction failure.');
    }
    publicationAssertSame('importing', $pdo->query("SELECT status FROM dataset WHERE id = {$transactionDatasetId}")->fetchColumn(), 'Candidate status did not roll back.');
    publicationAssertSame('ready', $pdo->query("SELECT status FROM dataset WHERE id = {$secondDatasetId}")->fetchColumn(), 'Previous active status did not roll back.');
    publicationAssertSame($secondDatasetId, (int) $pdo->query('SELECT dataset_id FROM active_dataset WHERE slot = 1')->fetchColumn(), 'Pointer did not roll back atomically.');

    $directoriesBefore = is_dir($storageRoot) ? glob($storageRoot . '/*', GLOB_ONLYDIR) : [];
    $invalidImporter = new FixtureTerritoryImporter($pdo, $runs, $firstCode);
    $invalidRunner = new FullImportRunner($runs, $invalidImporter, $publication, $root);
    try {
        $invalidRunner->run($scope);
        throw new RuntimeException('Invalid orchestrated dataset unexpectedly activated.');
    } catch (ImportException $exception) {
        publicationAssertSame('dataset_validation_failed', $exception->errorCode, 'Orchestrated validation failure code differs.');
    }
    $directoriesAfter = is_dir($storageRoot) ? glob($storageRoot . '/*', GLOB_ONLYDIR) : [];
    $fixtureRunDirectories = array_values(array_diff($directoriesAfter ?: [], $directoriesBefore ?: []));
    publicationAssertSame(1, count($fixtureRunDirectories), 'Failed run artifacts were not retained in one run directory.');
    $failedDataset = $pdo->query('SELECT id, status, validation_report FROM dataset ORDER BY id DESC LIMIT 1')->fetch();
    publicationAssertSame('failed', $failedDataset['status'], 'Orchestrated validation failure did not fail its dataset.');
    publicationAssertSame($secondDatasetId, (int) $pdo->query('SELECT dataset_id FROM active_dataset WHERE slot = 1')->fetchColumn(), 'Failed orchestrated run changed active pointer.');

    echo "Dataset publication/full-run integration verification passed.\n";
} finally {
    if ($pdo instanceof PDO) {
        clearPublicationDatabase($pdo);
    }
    foreach ($fixtureRunDirectories as $directory) {
        removePublicationDirectory($directory, $storageRoot);
    }
}
