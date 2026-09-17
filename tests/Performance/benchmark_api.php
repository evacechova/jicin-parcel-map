<?php

declare(strict_types=1);

use App\Api\BoundingBox;
use App\Api\MapApi;
use App\Api\MapApiConfig;
use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\MigrationRunner;
use App\Database\TestDatabaseGuard;
use App\Geo\ViewportEnvelopeBuilder;
use App\Http\Request;
use App\Read\DatabaseMapReadService;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();

function benchmarkClear(PDO $pdo): void
{
    $pdo->exec('DELETE FROM parcel');
    $pdo->exec('DELETE FROM cadastral_territory');
    $pdo->exec('DELETE FROM import_territory');
    $pdo->exec('DELETE FROM active_dataset');
    $pdo->exec('DELETE FROM dataset');
}

/** @param list<float> $values */
function benchmarkPercentile(array $values, float $percentile): float
{
    sort($values);
    $index = (int) ceil(count($values) * $percentile) - 1;

    return $values[max(0, min(count($values) - 1, $index))];
}

/** @return array{int, array<string, mixed>, float} */
function benchmarkRequest(MapApi $api, string $uri): array
{
    $parts = parse_url($uri);
    $start = hrtime(true);
    $response = $api->handle(new Request('GET', (string) $parts['path'], (string) ($parts['query'] ?? '')));
    $milliseconds = (hrtime(true) - $start) / 1_000_000;

    return [$response->status, $response->body, $milliseconds];
}

$pdo = null;
try {
    $config = DatabaseConfig::fromEnvironment('TEST_DB_');
    TestDatabaseGuard::assertSafeConfig($config);
    $pdo = ConnectionFactory::create($config);
    TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    if (!str_starts_with($version, '8.4.')) {
        throw new RuntimeException(sprintf('API benchmark requires MySQL 8.4; got %s.', $version));
    }
    (new MigrationRunner($pdo, $root . '/database/migrations'))->migrate();
    benchmarkClear($pdo);

    $pdo->exec(<<<'SQL'
        INSERT INTO dataset (
            country_code, provider, scope_code, native_srid, display_srid,
            source_url, status, territory_count, parcel_count, completed_at
        ) VALUES (
            'CZ', 'CUZK', 'benchmark', 5514, 4326,
            'https://example.test/benchmark', 'ready', 1, 20000, CURRENT_TIMESTAMP(6)
        )
        SQL);
    $datasetId = (int) $pdo->lastInsertId();
    $pdo->prepare(<<<'SQL'
        INSERT INTO cadastral_territory (dataset_id, ku_code, name, inspire_id, geom_native)
        VALUES (
            :dataset_id, '999999', 'Benchmark territory', 'CZ.BENCHMARK.TERRITORY',
            ST_SRID(
                ST_Transform(
                    ST_GeomFromText(
                        'MULTIPOLYGON(((15.20 50.30,15.44 50.30,15.44 50.44,15.20 50.44,15.20 50.30)))',
                        4326,
                        'axis-order=long-lat'
                    ),
                    1005514
                ),
                5514
            )
        )
        SQL)->execute(['dataset_id' => $datasetId]);
    $territoryId = (int) $pdo->lastInsertId();
    $pdo->prepare(<<<'SQL'
        INSERT INTO import_territory (
            dataset_id, ku_code, source_url, source_checksum, source_size_bytes,
            retrieved_at, status, attempt_count, last_attempt_at, last_http_status, parcel_count
        ) VALUES (
            :dataset_id, '999999', 'https://example.test/benchmark.zip', :checksum, 1,
            CURRENT_TIMESTAMP(6), 'imported', 1, CURRENT_TIMESTAMP(6), 200, 20000
        )
        SQL)->execute(['dataset_id' => $datasetId, 'checksum' => hash('sha256', 'benchmark')]);

    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO parcel (
            dataset_id, territory_id, inspire_id, label,
            national_cadastral_reference, area_m2, geom_native
        ) VALUES (
            :dataset_id, :territory_id, :inspire_id, :label, :national_reference, 400,
            ST_SRID(
                ST_Transform(
                    ST_GeomFromText(:geometry_wkt, 4326, 'axis-order=long-lat'),
                    1005514
                ),
                5514
            )
        )
        SQL);
    $pdo->beginTransaction();
    for ($row = 0; $row < 100; ++$row) {
        for ($column = 0; $column < 200; ++$column) {
            $index = ($row * 200) + $column;
            $x = 15.20 + ($column * 0.0012);
            $y = 50.30 + ($row * 0.0014);
            $geometry = sprintf(
                'MULTIPOLYGON(((%.7F %.7F,%.7F %.7F,%.7F %.7F,%.7F %.7F,%.7F %.7F)))',
                $x, $y, $x + 0.0003, $y, $x + 0.0003, $y + 0.0003,
                $x, $y + 0.0003, $x, $y,
            );
            $id = sprintf('CP.BENCH.%05d', $index);
            $insert->execute([
                'dataset_id' => $datasetId,
                'territory_id' => $territoryId,
                'inspire_id' => $id,
                'label' => (string) $index,
                'national_reference' => $id,
                'geometry_wkt' => $geometry,
            ]);
        }
    }
    $pdo->commit();
    $pdo->prepare('INSERT INTO active_dataset (slot, dataset_id, activated_at) VALUES (1, :id, CURRENT_TIMESTAMP(6))')->execute(['id' => $datasetId]);

    $api = new MapApi(new DatabaseMapReadService($pdo, new MapApiConfig()), new MapApiConfig());
    $envelopeBuilder = new ViewportEnvelopeBuilder(
        $pdo,
        MapApiConfig::EDGE_SAMPLE_STEP_DEGREES,
        MapApiConfig::QUERY_MARGIN_METRES,
    );
    $scenarios = [
        'small' => ['15.30,50.35,15.31,50.36', new BoundingBox(15.30, 50.35, 15.31, 50.36)],
        'medium' => ['15.28,50.34,15.33,50.38', new BoundingBox(15.28, 50.34, 15.33, 50.38)],
        'too-dense' => ['14.80,50.15,15.95,50.85', new BoundingBox(14.80, 50.15, 15.95, 50.85)],
    ];

    echo "scenario\tbbox\tcandidates\tresults\tstatus\tp50_ms\tp95_ms\tpayload_bytes\tspatial_index\n";
    foreach ($scenarios as $name => [$bboxText, $bbox]) {
        $envelope = $envelopeBuilder->build($bbox);
        $candidate = $pdo->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM parcel FORCE INDEX (sp_parcel_geom_native)
            WHERE dataset_id = :dataset_id
              AND MBRIntersects(
                    geom_native,
                    ST_GeomFromText(:query_wkt, 5514, 'axis-order=srid-defined')
                  )
            SQL);
        $candidate->execute(['dataset_id' => $datasetId, 'query_wkt' => $envelope->polygonWkt()]);
        $candidateCount = (int) $candidate->fetchColumn();

        $exact = $pdo->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM parcel FORCE INDEX (sp_parcel_geom_native)
            WHERE dataset_id = :dataset_id
              AND MBRIntersects(
                    geom_native,
                    ST_GeomFromText(:mbr_wkt, 5514, 'axis-order=srid-defined')
                  )
              AND ST_Intersects(
                    geom_native,
                    ST_GeomFromText(:exact_wkt, 5514, 'axis-order=srid-defined')
                  )
            SQL);
        $exact->execute([
            'dataset_id' => $datasetId,
            'mbr_wkt' => $envelope->polygonWkt(),
            'exact_wkt' => $envelope->polygonWkt(),
        ]);
        $resultCount = (int) $exact->fetchColumn();

        $plan = $pdo->prepare(<<<'SQL'
            EXPLAIN ANALYZE
            SELECT id
            FROM parcel FORCE INDEX (sp_parcel_geom_native)
            WHERE dataset_id = :dataset_id
              AND MBRIntersects(
                    geom_native,
                    ST_GeomFromText(:query_wkt, 5514, 'axis-order=srid-defined')
                  )
            LIMIT 2001
            SQL);
        $plan->execute(['dataset_id' => $datasetId, 'query_wkt' => $envelope->polygonWkt()]);
        $planText = implode("\n", $plan->fetchAll(PDO::FETCH_COLUMN));
        $usesIndex = str_contains($planText, 'sp_parcel_geom_native');

        $durations = [];
        $status = 0;
        $body = [];
        for ($iteration = 0; $iteration < 10; ++$iteration) {
            [$status, $body, $duration] = benchmarkRequest(
                $api,
                '/api/v1/parcels?bbox=' . $bboxText . '&zoom=17',
            );
            $durations[] = $duration;
        }
        $payloadBytes = strlen(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        printf(
            "%s\t%s\t%d\t%d\t%d\t%.3f\t%.3f\t%d\t%s\n",
            $name,
            $bboxText,
            $candidateCount,
            $resultCount,
            $status,
            benchmarkPercentile($durations, 0.50),
            benchmarkPercentile($durations, 0.95),
            $payloadBytes,
            $usesIndex ? 'yes' : 'no',
        );
    }
} finally {
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        benchmarkClear($pdo);
    }
}
