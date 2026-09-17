<?php

declare(strict_types=1);

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

function apiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function apiAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
    }
}

function clearApiDatabase(PDO $pdo): void
{
    $pdo->exec('DELETE FROM parcel');
    $pdo->exec('DELETE FROM cadastral_territory');
    $pdo->exec('DELETE FROM import_territory');
    $pdo->exec('DELETE FROM active_dataset');
    $pdo->exec('DELETE FROM dataset');
}

function apiDataset(PDO $pdo, string $scope, string $status): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO dataset (
            country_code, provider, scope_code, native_srid, display_srid,
            source_url, status, territory_count, parcel_count, completed_at
        ) VALUES (
            'CZ', 'CUZK', :scope, 5514, 4326,
            'https://example.test/source', :status, 1, 0, CURRENT_TIMESTAMP(6)
        )
        SQL);
    $statement->execute(['scope' => $scope, 'status' => $status]);

    return (int) $pdo->lastInsertId();
}

function apiTerritory(PDO $pdo, int $datasetId, string $kuCode, string $name): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO cadastral_territory (dataset_id, ku_code, name, inspire_id, geom_native)
        VALUES (
            :dataset_id, :ku_code, :name, :inspire_id,
            ST_SRID(
                ST_Transform(
                    ST_GeomFromText(
                        'MULTIPOLYGON(((15.280 50.380,15.340 50.380,15.340 50.440,15.280 50.440,15.280 50.380)))',
                        4326,
                        'axis-order=long-lat'
                    ),
                    1005514
                ),
                5514
            )
        )
        SQL);
    $statement->execute([
        'dataset_id' => $datasetId,
        'ku_code' => $kuCode,
        'name' => $name,
        'inspire_id' => 'CZ.TERRITORY.' . $datasetId,
    ]);
    $territoryId = (int) $pdo->lastInsertId();

    $checkpoint = $pdo->prepare(<<<'SQL'
        INSERT INTO import_territory (
            dataset_id, ku_code, source_url, source_checksum, source_size_bytes,
            retrieved_at, status, attempt_count, last_attempt_at, last_http_status, parcel_count
        ) VALUES (
            :dataset_id, :ku_code, 'https://example.test/fixture.zip', :checksum, 100,
            CURRENT_TIMESTAMP(6), 'imported', 1, CURRENT_TIMESTAMP(6), 200, 0
        )
        SQL);
    $checkpoint->execute([
        'dataset_id' => $datasetId,
        'ku_code' => $kuCode,
        'checksum' => hash('sha256', (string) $datasetId),
    ]);

    return $territoryId;
}

function apiParcel(
    PDO $pdo,
    int $datasetId,
    int $territoryId,
    string $inspireId,
    string $label,
    string $wgs84MultiPolygon,
): void {
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO parcel (
            dataset_id, territory_id, inspire_id, label,
            national_cadastral_reference, area_m2, geom_native
        ) VALUES (
            :dataset_id, :territory_id, :inspire_id, :label,
            :national_reference, 25.50,
            ST_SRID(
                ST_Transform(
                    ST_GeomFromText(:geometry_wkt, 4326, 'axis-order=long-lat'),
                    1005514
                ),
                5514
            )
        )
        SQL);
    $statement->execute([
        'dataset_id' => $datasetId,
        'territory_id' => $territoryId,
        'inspire_id' => $inspireId,
        'label' => $label,
        'national_reference' => 'N-' . $inspireId,
        'geometry_wkt' => $wgs84MultiPolygon,
    ]);
}

/** @return array{int, array<string, mixed>} */
function apiResponse(MapApi $api, string $uri): array
{
    $parts = parse_url($uri);
    $response = $api->handle(new Request('GET', (string) $parts['path'], (string) ($parts['query'] ?? '')));

    return [$response->status, $response->body];
}

$pdo = null;
try {
    $config = DatabaseConfig::fromEnvironment('TEST_DB_');
    TestDatabaseGuard::assertSafeConfig($config);
    $pdo = ConnectionFactory::create($config);
    TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    apiAssert(str_starts_with($version, '8.4.'), sprintf('API integration test requires MySQL 8.4; got %s.', $version));
    (new MigrationRunner($pdo, $root . '/database/migrations'))->migrate();
    clearApiDatabase($pdo);

    $historicalId = apiDataset($pdo, 'historical_ready', 'ready');
    $historicalTerritoryId = apiTerritory($pdo, $historicalId, '111111', 'Historical territory');
    apiParcel(
        $pdo,
        $historicalId,
        $historicalTerritoryId,
        'CP.HISTORICAL',
        'historical',
        'MULTIPOLYGON(((15.304 50.404,15.306 50.404,15.306 50.406,15.304 50.406,15.304 50.404)))',
    );

    $activeId = apiDataset($pdo, 'active_ready', 'ready');
    $activeTerritoryId = apiTerritory($pdo, $activeId, '222222', 'Active territory');
    $fixtures = [
        ['CP.INSIDE', 'inside', 'MULTIPOLYGON(((15.304 50.404,15.306 50.404,15.306 50.406,15.304 50.406,15.304 50.404)))'],
        ['CP.WEST', 'west edge', 'MULTIPOLYGON(((15.299 50.404,15.301 50.404,15.301 50.406,15.299 50.406,15.299 50.404)))'],
        ['CP.EAST', 'east edge', 'MULTIPOLYGON(((15.309 50.404,15.311 50.404,15.311 50.406,15.309 50.406,15.309 50.404)))'],
        ['CP.SOUTH', 'south edge', 'MULTIPOLYGON(((15.304 50.399,15.306 50.399,15.306 50.401,15.304 50.401,15.304 50.399)))'],
        ['CP.NORTH', 'north edge', 'MULTIPOLYGON(((15.304 50.409,15.306 50.409,15.306 50.411,15.304 50.411,15.304 50.409)))'],
        ['CP.CORNER', 'corner', 'MULTIPOLYGON(((15.3095 50.4095,15.3105 50.4095,15.3105 50.4105,15.3095 50.4105,15.3095 50.4095)))'],
        ['CP.OUTSIDE', 'outside', 'MULTIPOLYGON(((15.320 50.420,15.322 50.420,15.322 50.422,15.320 50.422,15.320 50.420)))'],
    ];
    foreach ($fixtures as [$id, $label, $geometry]) {
        apiParcel($pdo, $activeId, $activeTerritoryId, $id, $label, $geometry);
    }
    $pdo->prepare('UPDATE dataset SET parcel_count = 1 WHERE id = :id')->execute(['id' => $historicalId]);
    $pdo->prepare('UPDATE import_territory SET parcel_count = 1 WHERE dataset_id = :id')->execute(['id' => $historicalId]);
    $pdo->prepare('UPDATE dataset SET parcel_count = 7 WHERE id = :id')->execute(['id' => $activeId]);
    $pdo->prepare('UPDATE import_territory SET parcel_count = 7 WHERE dataset_id = :id')->execute(['id' => $activeId]);
    $pdo->prepare('INSERT INTO active_dataset (slot, dataset_id, activated_at) VALUES (1, :id, CURRENT_TIMESTAMP(6))')->execute(['id' => $activeId]);

    $api = new MapApi(new DatabaseMapReadService($pdo, new MapApiConfig()), new MapApiConfig());
    [$status, $body] = apiResponse($api, '/api/v1/cadastral-territories?bbox=14.80,50.15,15.95,50.85');
    apiAssertSame(200, $status, 'KÚ endpoint status differs.');
    apiAssertSame('FeatureCollection', $body['type'] ?? null, 'KÚ response is not a FeatureCollection.');
    apiAssertSame(1, count($body['features'] ?? []), 'KÚ endpoint leaked an inactive ready dataset.');
    apiAssertSame('222222', $body['features'][0]['id'] ?? null, 'KÚ endpoint returned the wrong active dataset.');
    apiAssertSame(7, $body['features'][0]['properties']['parcel_count'] ?? null, 'KÚ parcel count differs.');
    apiAssertSame('MultiPolygon', $body['features'][0]['geometry']['type'] ?? null, 'KÚ geometry is not GeoJSON MultiPolygon.');
    $firstTerritoryCoordinate = $body['features'][0]['geometry']['coordinates'][0][0][0] ?? [];
    apiAssert(abs((float) ($firstTerritoryCoordinate[0] ?? 0.0) - 15.28) < 0.000001, 'KÚ GeoJSON is not longitude-first EPSG:4326.');
    apiAssert(abs((float) ($firstTerritoryCoordinate[1] ?? 0.0) - 50.38) < 0.000001, 'KÚ GeoJSON latitude differs.');

    [$status, $body] = apiResponse($api, '/api/v1/parcels?bbox=15.30,50.40,15.31,50.41&zoom=17');
    apiAssertSame(200, $status, 'Parcel BBOX endpoint status differs.');
    $ids = array_column($body['features'] ?? [], 'id');
    sort($ids);
    apiAssertSame(
        ['CP.CORNER', 'CP.EAST', 'CP.INSIDE', 'CP.NORTH', 'CP.SOUTH', 'CP.WEST'],
        $ids,
        'Parcel BBOX inclusion/exclusion or dataset isolation differs.',
    );
    apiAssert(!in_array('CP.HISTORICAL', $ids, true), 'Inactive ready dataset parcel leaked.');
    apiAssert(!in_array('CP.OUTSIDE', $ids, true), 'Outside parcel was returned.');
    foreach ($body['features'] as $feature) {
        apiAssertSame('MultiPolygon', $feature['geometry']['type'] ?? null, 'Parcel geometry type differs.');
        apiAssertSame(['label'], array_keys($feature['properties'] ?? []), 'Parcel payload exposes unexpected properties.');
    }

    [$status, $body] = apiResponse($api, '/api/v1/parcels?bbox=15.80,50.70,15.81,50.71&zoom=17');
    apiAssertSame(200, $status, 'Empty BBOX status differs.');
    apiAssertSame([], $body['features'] ?? null, 'Empty BBOX did not return an empty FeatureCollection.');

    [$status, $body] = apiResponse($api, '/api/v1/parcels/CP.INSIDE');
    apiAssertSame(200, $status, 'Parcel detail status differs.');
    apiAssertSame('inside', $body['data']['label'] ?? null, 'Parcel detail label differs.');
    apiAssertSame(25.5, $body['data']['area_m2'] ?? null, 'Parcel detail area differs.');
    apiAssertSame('222222', $body['data']['cadastral_territory']['ku_code'] ?? null, 'Parcel detail KÚ differs.');
    apiAssert(!isset($body['data']['dataset_id']), 'Parcel detail exposed internal dataset metadata.');

    [$status, $body] = apiResponse($api, '/api/v1/parcels/CP.HISTORICAL');
    apiAssertSame(404, $status, 'Inactive ready parcel detail leaked.');
    apiAssertSame('parcel_not_found', $body['error']['code'] ?? null, 'Missing parcel error differs.');

    $envelope = (new ViewportEnvelopeBuilder(
        $pdo,
        MapApiConfig::EDGE_SAMPLE_STEP_DEGREES,
        MapApiConfig::QUERY_MARGIN_METRES,
    ))->build(new App\Api\BoundingBox(15.30, 50.40, 15.31, 50.41));
    $plan = $pdo->prepare(<<<'SQL'
        EXPLAIN ANALYZE
        SELECT parcel.id
        FROM parcel FORCE INDEX (sp_parcel_geom_native)
        STRAIGHT_JOIN active_dataset AS active
            ON active.slot = 1 AND active.dataset_id = parcel.dataset_id
        STRAIGHT_JOIN dataset
            ON dataset.id = active.dataset_id AND dataset.status = 'ready'
        WHERE parcel.dataset_id = :dataset_id
          AND MBRIntersects(
                parcel.geom_native,
                ST_GeomFromText(:mbr_wkt, 5514, 'axis-order=srid-defined')
              )
          AND ST_Intersects(
                parcel.geom_native,
                ST_GeomFromText(:exact_wkt, 5514, 'axis-order=srid-defined')
              )
        SQL);
    $plan->execute([
        'dataset_id' => $activeId,
        'mbr_wkt' => $envelope->polygonWkt(),
        'exact_wkt' => $envelope->polygonWkt(),
    ]);
    $planText = implode("\n", $plan->fetchAll(PDO::FETCH_COLUMN));
    apiAssert(str_contains($planText, 'sp_parcel_geom_native'), 'EXPLAIN ANALYZE did not use the parcel spatial index.');

    $denseInsert = $pdo->prepare(<<<'SQL'
        INSERT INTO parcel (
            dataset_id, territory_id, inspire_id, label,
            national_cadastral_reference, area_m2, geom_native
        ) VALUES (
            :dataset_id, :territory_id, :inspire_id, :label, :national_reference, 1,
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
    for ($index = 0; $index < MapApiConfig::PARCEL_FEATURE_LIMIT + 1; ++$index) {
        $x = 15.302 + (($index % 50) * 0.0001);
        $y = 50.402 + ((intdiv($index, 50) % 41) * 0.0001);
        $geometry = sprintf(
            'MULTIPOLYGON(((%.7F %.7F,%.7F %.7F,%.7F %.7F,%.7F %.7F,%.7F %.7F)))',
            $x, $y, $x + 0.00002, $y, $x + 0.00002, $y + 0.00002,
            $x, $y + 0.00002, $x, $y,
        );
        $id = sprintf('CP.DENSE.%04d', $index);
        $denseInsert->execute([
            'dataset_id' => $activeId,
            'territory_id' => $activeTerritoryId,
            'inspire_id' => $id,
            'label' => $id,
            'national_reference' => $id,
            'geometry_wkt' => $geometry,
        ]);
    }
    $pdo->commit();
    [$status, $body] = apiResponse($api, '/api/v1/parcels?bbox=15.30,50.40,15.31,50.41&zoom=17');
    apiAssertSame(409, $status, 'Dense viewport did not activate the safety limit.');
    apiAssertSame('too_dense', $body['error']['code'] ?? null, 'Dense viewport error differs.');
    $pdo->exec("DELETE FROM parcel WHERE inspire_id LIKE 'CP.DENSE.%'");

    $pdo->exec('DELETE FROM active_dataset');
    [$status, $body] = apiResponse($api, '/api/v1/parcels?bbox=15.30,50.40,15.31,50.41&zoom=17');
    apiAssertSame(503, $status, 'Missing active dataset status differs.');
    apiAssertSame('dataset_unavailable', $body['error']['code'] ?? null, 'Missing active dataset error differs.');

    $unavailableId = apiDataset($pdo, 'unavailable', 'importing');
    $pdo->prepare('INSERT INTO active_dataset (slot, dataset_id, activated_at) VALUES (1, :id, CURRENT_TIMESTAMP(6))')->execute(['id' => $unavailableId]);
    foreach (['importing', 'failed', 'retired'] as $unavailableStatus) {
        $pdo->prepare('UPDATE dataset SET status = :status WHERE id = :id')->execute([
            'status' => $unavailableStatus,
            'id' => $unavailableId,
        ]);
        [$status, $body] = apiResponse($api, '/api/v1/cadastral-territories?bbox=14.80,50.15,15.95,50.85');
        apiAssertSame(503, $status, sprintf('Pointer to %s dataset became visible.', $unavailableStatus));
        apiAssertSame('dataset_unavailable', $body['error']['code'] ?? null, 'Unavailable active dataset error differs.');
    }

    echo "API/MySQL integration verification passed on MySQL {$version}.\n";
    echo "Verified active-pointer isolation, GeoJSON 4326, spatial edge fixtures, index plan and safety cap.\n";
} finally {
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        clearApiDatabase($pdo);
    }
}
