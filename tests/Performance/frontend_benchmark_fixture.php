<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\MigrationRunner;
use App\Database\TestDatabaseGuard;
use App\Import\Scope\CadastralScope;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();

$action = $argv[1] ?? '';
if (!in_array($action, ['seed', 'clean'], true)) {
    fwrite(STDERR, "Usage: php tests/Performance/frontend_benchmark_fixture.php seed|clean\n");
    exit(2);
}

$config = DatabaseConfig::fromEnvironment('TEST_DB_');
TestDatabaseGuard::assertSafeConfig($config);
$pdo = ConnectionFactory::create($config);
TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
if (!str_starts_with($version, '8.4.')) {
    throw new RuntimeException(sprintf('Frontend benchmark fixture requires MySQL 8.4; got %s.', $version));
}

(new MigrationRunner($pdo, $root . '/database/migrations'))->migrate();
clearFrontendBenchmark($pdo);
if ($action === 'clean') {
    echo "Frontend benchmark fixture removed.\n";
    exit(0);
}

$scope = CadastralScope::load('jicin', $root);
$parcelCount = 1500;
$pdo->beginTransaction();
try {
    $pdo->prepare(<<<'SQL'
        INSERT INTO dataset (
            country_code, provider, scope_code, native_srid, display_srid,
            source_url, status, territory_count, parcel_count, completed_at
        ) VALUES (
            'CZ', 'CUZK', 'frontend_benchmark', 5514, 4326,
            'https://example.test/frontend-benchmark', 'ready', 240, :parcel_count,
            CURRENT_TIMESTAMP(6)
        )
        SQL)->execute(['parcel_count' => $parcelCount]);
    $datasetId = (int) $pdo->lastInsertId();

    $insertTerritory = $pdo->prepare(<<<'SQL'
        INSERT INTO cadastral_territory (dataset_id, ku_code, name, inspire_id, geom_native)
        VALUES (
            :dataset_id, :ku_code, :name, :inspire_id,
            ST_SRID(
                ST_Transform(
                    ST_GeomFromText(:geometry_wkt, 4326, 'axis-order=long-lat'),
                    1005514
                ),
                5514
            )
        )
        SQL);
    $insertCheckpoint = $pdo->prepare(<<<'SQL'
        INSERT INTO import_territory (
            dataset_id, ku_code, source_url, source_checksum, source_size_bytes,
            retrieved_at, status, attempt_count, last_attempt_at, last_http_status, parcel_count
        ) VALUES (
            :dataset_id, :ku_code, 'https://example.test/frontend-benchmark.zip', :checksum, 1,
            CURRENT_TIMESTAMP(6), 'imported', 1, CURRENT_TIMESTAMP(6), 200, :parcel_count
        )
        SQL);

    $jicinTerritoryId = null;
    foreach (array_values($scope->territories) as $index => $name) {
        $kuCode = $scope->territoryCodes()[$index];
        $column = $index % 20;
        $row = intdiv($index, 20);
        $west = 14.82 + ($column * 0.055);
        $south = 50.17 + ($row * 0.052);
        $east = $west + 0.043;
        $north = $south + 0.04;
        $insertTerritory->execute([
            'dataset_id' => $datasetId,
            'ku_code' => $kuCode,
            'name' => $name,
            'inspire_id' => 'CZ.BENCHMARK.TERRITORY.' . $kuCode,
            'geometry_wkt' => rectangleWkt($west, $south, $east, $north),
        ]);
        $territoryId = (int) $pdo->lastInsertId();
        if ($kuCode === '659541') {
            $jicinTerritoryId = $territoryId;
        }
        $insertCheckpoint->execute([
            'dataset_id' => $datasetId,
            'ku_code' => $kuCode,
            'checksum' => hash('sha256', 'frontend-benchmark-' . $kuCode),
            'parcel_count' => $kuCode === '659541' ? $parcelCount : 0,
        ]);
    }

    if ($jicinTerritoryId === null) {
        throw new RuntimeException('The Jičín benchmark territory was not created.');
    }

    $insertParcel = $pdo->prepare(<<<'SQL'
        INSERT INTO parcel (
            dataset_id, territory_id, inspire_id, label,
            national_cadastral_reference, area_m2, geom_native
        ) VALUES (
            :dataset_id, :territory_id, :inspire_id, :label,
            :national_reference, :area_m2,
            ST_SRID(
                ST_Transform(
                    ST_GeomFromText(:geometry_wkt, 4326, 'axis-order=long-lat'),
                    1005514
                ),
                5514
            )
        )
        SQL);
    for ($index = 0; $index < $parcelCount; ++$index) {
        $column = $index % 50;
        $row = intdiv($index, 50);
        $centreLongitude = 15.057 + ($column * 0.00018);
        $centreLatitude = 50.3433 + ($row * 0.00018);
        $inspireId = sprintf('CP.FRONTEND.%04d', $index + 1);
        $label = sprintf('%d/%d', $index + 1, ($index % 9) + 1);
        $insertParcel->execute([
            'dataset_id' => $datasetId,
            'territory_id' => $jicinTerritoryId,
            'inspire_id' => $inspireId,
            'label' => $label,
            'national_reference' => '659541-' . $label,
            'area_m2' => number_format(35 + ($index % 400), 2, '.', ''),
            'geometry_wkt' => parcelWkt($centreLongitude, $centreLatitude),
        ]);
    }

    $pdo->prepare(<<<'SQL'
        INSERT INTO active_dataset (slot, dataset_id, activated_at)
        VALUES (1, :dataset_id, CURRENT_TIMESTAMP(6))
        SQL)->execute(['dataset_id' => $datasetId]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$pdo->exec('ANALYZE TABLE cadastral_territory, parcel');
printf(
    "Frontend benchmark fixture ready on MySQL %s: 240 KÚ, %d parcels, active dataset %d.\n",
    $version,
    $parcelCount,
    $datasetId,
);

function clearFrontendBenchmark(PDO $pdo): void
{
    $pdo->exec('DELETE FROM parcel');
    $pdo->exec('DELETE FROM cadastral_territory');
    $pdo->exec('DELETE FROM import_territory');
    $pdo->exec('DELETE FROM active_dataset');
    $pdo->exec('DELETE FROM dataset');
}

function rectangleWkt(float $west, float $south, float $east, float $north): string
{
    return sprintf(
        'MULTIPOLYGON(((%.8F %.8F,%.8F %.8F,%.8F %.8F,%.8F %.8F,%.8F %.8F)))',
        $west,
        $south,
        $east,
        $south,
        $east,
        $north,
        $west,
        $north,
        $west,
        $south,
    );
}

function parcelWkt(float $centreLongitude, float $centreLatitude): string
{
    $points = [];
    for ($vertex = 0; $vertex < 16; ++$vertex) {
        $angle = (2 * M_PI * $vertex) / 16;
        $points[] = sprintf(
            '%.8F %.8F',
            $centreLongitude + (cos($angle) * 0.00007),
            $centreLatitude + (sin($angle) * 0.00005),
        );
    }
    $points[] = $points[0];

    return 'MULTIPOLYGON(((' . implode(',', $points) . ')))';
}
