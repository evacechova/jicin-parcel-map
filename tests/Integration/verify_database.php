<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\MigrationRunner;
use App\Database\TestDatabaseGuard;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s Expected %s, got %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectDatabaseRejection(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException(sprintf('Database accepted invalid state: %s.', $message));
}

function insertDataset(PDO $pdo, string $scope, string $status): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO dataset (
            country_code, provider, scope_code, native_srid, display_srid,
            source_url, status
        ) VALUES ('CZ', 'CUZK', :scope, 5514, 4326, 'https://example.test/source', :status)
        SQL);
    $statement->execute(['scope' => $scope, 'status' => $status]);

    return (int) $pdo->lastInsertId();
}

function insertTerritory(PDO $pdo, int $datasetId, string $kuCode, string $inspireId): int
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO cadastral_territory (
            dataset_id, ku_code, name, inspire_id, geom_native, reference_point_native
        ) VALUES (
            :dataset_id, :ku_code, :name, :inspire_id,
            ST_GeomFromText(
                'MULTIPOLYGON(((-672020 -1013110, -671940 -1013110, -671940 -1013030, -672020 -1013030, -672020 -1013110)))',
                5514,
                'axis-order=srid-defined'
            ),
            ST_GeomFromText('POINT(-671984.1403374915 -1013081.1797817094)', 5514, 'axis-order=srid-defined')
        )
        SQL);
    $statement->execute([
        'dataset_id' => $datasetId,
        'ku_code' => $kuCode,
        'name' => 'Test territory ' . $kuCode,
        'inspire_id' => $inspireId,
    ]);

    return (int) $pdo->lastInsertId();
}

function insertParcel(
    PDO $pdo,
    int $datasetId,
    int $territoryId,
    string $inspireId,
    string $wkt,
): int {
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO parcel (
            dataset_id, territory_id, inspire_id, label,
            national_cadastral_reference, area_m2, geom_native
        ) VALUES (
            :dataset_id, :territory_id, :inspire_id, :label,
            :national_reference, 25.00,
            ST_GeomFromText(:wkt, 5514, 'axis-order=srid-defined')
        )
        SQL);
    $statement->execute([
        'dataset_id' => $datasetId,
        'territory_id' => $territoryId,
        'inspire_id' => $inspireId,
        'label' => $inspireId,
        'national_reference' => 'N-' . $inspireId,
        'wkt' => $wkt,
    ]);

    return (int) $pdo->lastInsertId();
}

$queryEnvelopeWkt = 'POLYGON((-672000 -1013090, -671980 -1013090, -671980 -1013070, -672000 -1013070, -672000 -1013090))';
$insideWkt = 'MULTIPOLYGON(((-671995 -1013085, -671990 -1013085, -671990 -1013080, -671995 -1013080, -671995 -1013085)))';
$outsideWkt = 'MULTIPOLYGON(((-671950 -1013040, -671945 -1013040, -671945 -1013035, -671950 -1013035, -671950 -1013040)))';
$mbrOnlyWkt = 'MULTIPOLYGON(((-671985 -1013060, -671970 -1013075, -671970 -1013060, -671985 -1013060)))';

try {
    $config = DatabaseConfig::fromEnvironment('TEST_DB_');
    TestDatabaseGuard::assertSafeConfig($config);
    $pdo = ConnectionFactory::create($config);
    TestDatabaseGuard::assertConnectedDatabase($pdo, $config);

    $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    assertTrue(str_starts_with($serverVersion, '8.4.'), sprintf(
        'Database verification requires MySQL 8.4 LTS; server reports %s.',
        $serverVersion,
    ));

    $runner = new MigrationRunner($pdo, $root . '/database/migrations');
    $runner->migrate();

    $columnRows = $pdo->query(<<<'SQL'
        SELECT
            TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA,
            CHARACTER_SET_NAME, COLLATION_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND (
              (TABLE_NAME = 'dataset' AND COLUMN_NAME = 'id')
              OR (TABLE_NAME = 'active_dataset' AND COLUMN_NAME = 'dataset_id')
              OR (TABLE_NAME = 'import_territory' AND COLUMN_NAME IN ('dataset_id', 'ku_code'))
              OR (TABLE_NAME = 'cadastral_territory' AND COLUMN_NAME IN ('id', 'dataset_id', 'ku_code', 'inspire_id', 'geom_native'))
              OR (TABLE_NAME = 'parcel' AND COLUMN_NAME IN ('id', 'dataset_id', 'territory_id', 'inspire_id', 'geom_native'))
          )
        ORDER BY TABLE_NAME, COLUMN_NAME
        SQL)->fetchAll();
    $columns = [];
    foreach ($columnRows as $row) {
        $columns[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = $row;
    }

    foreach (['dataset.id', 'cadastral_territory.id', 'parcel.id'] as $column) {
        assertSameValue('bigint unsigned', $columns[$column]['COLUMN_TYPE'] ?? null, sprintf('%s has the wrong type.', $column));
        assertSameValue('NO', $columns[$column]['IS_NULLABLE'] ?? null, sprintf('%s must be NOT NULL.', $column));
        assertTrue(str_contains((string) ($columns[$column]['EXTRA'] ?? ''), 'auto_increment'), sprintf('%s must auto-increment.', $column));
    }

    foreach ([
        'active_dataset.dataset_id',
        'import_territory.dataset_id',
        'cadastral_territory.dataset_id',
        'parcel.dataset_id',
        'parcel.territory_id',
    ] as $column) {
        assertSameValue('bigint unsigned', $columns[$column]['COLUMN_TYPE'] ?? null, sprintf('%s has the wrong relationship type.', $column));
        assertSameValue('NO', $columns[$column]['IS_NULLABLE'] ?? null, sprintf('%s must be NOT NULL.', $column));
    }

    foreach (['import_territory.ku_code', 'cadastral_territory.ku_code'] as $column) {
        assertSameValue('char(6)', $columns[$column]['COLUMN_TYPE'] ?? null, sprintf('%s must be CHAR(6).', $column));
        assertSameValue('NO', $columns[$column]['IS_NULLABLE'] ?? null, sprintf('%s must be NOT NULL.', $column));
        assertSameValue('ascii', $columns[$column]['CHARACTER_SET_NAME'] ?? null, sprintf('%s has the wrong character set.', $column));
        assertSameValue('ascii_bin', $columns[$column]['COLLATION_NAME'] ?? null, sprintf('%s has the wrong collation.', $column));
    }

    foreach (['cadastral_territory.inspire_id', 'parcel.inspire_id', 'cadastral_territory.geom_native', 'parcel.geom_native'] as $column) {
        assertSameValue('NO', $columns[$column]['IS_NULLABLE'] ?? null, sprintf('%s must be NOT NULL.', $column));
    }

    $srsIds = $pdo->query(<<<'SQL'
        SELECT SRS_ID
        FROM INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS
        WHERE SRS_ID IN (4326, 5514)
        ORDER BY SRS_ID
        SQL)->fetchAll(PDO::FETCH_COLUMN);
    assertSameValue(['4326', '5514'], array_map('strval', $srsIds), 'Required SRS definitions are missing.');

    $geometryRows = $pdo->query(<<<'SQL'
        SELECT TABLE_NAME, COLUMN_NAME, SRS_ID, GEOMETRY_TYPE_NAME
        FROM INFORMATION_SCHEMA.ST_GEOMETRY_COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('cadastral_territory', 'parcel')
        ORDER BY TABLE_NAME, COLUMN_NAME
        SQL)->fetchAll();
    $geometryColumns = [];
    foreach ($geometryRows as $row) {
        $geometryColumns[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = [
            (int) $row['SRS_ID'],
            strtoupper((string) $row['GEOMETRY_TYPE_NAME']),
        ];
    }
    assertSameValue([5514, 'MULTIPOLYGON'], $geometryColumns['cadastral_territory.geom_native'] ?? null, 'Territory geometry contract differs.');
    assertSameValue([5514, 'POINT'], $geometryColumns['cadastral_territory.reference_point_native'] ?? null, 'Territory reference-point contract differs.');
    assertSameValue([5514, 'MULTIPOLYGON'], $geometryColumns['parcel.geom_native'] ?? null, 'Parcel geometry contract differs.');
    assertSameValue([5514, 'POINT'], $geometryColumns['parcel.reference_point_native'] ?? null, 'Parcel reference-point contract differs.');

    $indexRows = $pdo->query(<<<'SQL'
        SELECT TABLE_NAME, INDEX_NAME, INDEX_TYPE
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND INDEX_NAME IN (
              'sp_cadastral_territory_geom_native',
              'sp_parcel_geom_native',
              'idx_parcel_dataset_territory'
          )
        ORDER BY TABLE_NAME, INDEX_NAME
        SQL)->fetchAll();
    $indexes = [];
    foreach ($indexRows as $row) {
        $indexes[$row['INDEX_NAME']] = strtoupper((string) $row['INDEX_TYPE']);
    }
    assertSameValue('SPATIAL', $indexes['sp_cadastral_territory_geom_native'] ?? null, 'Territory spatial index is missing.');
    assertSameValue('SPATIAL', $indexes['sp_parcel_geom_native'] ?? null, 'Parcel spatial index is missing.');
    assertSameValue('BTREE', $indexes['idx_parcel_dataset_territory'] ?? null, 'Parcel relationship index is missing.');

    $fkRows = $pdo->query(<<<'SQL'
        SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'parcel'
          AND CONSTRAINT_NAME = 'fk_parcel_territory_dataset'
        ORDER BY ORDINAL_POSITION
        SQL)->fetchAll();
    assertSameValue(
        [
            ['COLUMN_NAME' => 'dataset_id', 'REFERENCED_TABLE_NAME' => 'cadastral_territory', 'REFERENCED_COLUMN_NAME' => 'dataset_id', 'ORDINAL_POSITION' => 1],
            ['COLUMN_NAME' => 'territory_id', 'REFERENCED_TABLE_NAME' => 'cadastral_territory', 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 2],
        ],
        array_map(static fn (array $row): array => [
            'COLUMN_NAME' => $row['COLUMN_NAME'],
            'REFERENCED_TABLE_NAME' => $row['REFERENCED_TABLE_NAME'],
            'REFERENCED_COLUMN_NAME' => $row['REFERENCED_COLUMN_NAME'],
            'ORDINAL_POSITION' => (int) $row['ORDINAL_POSITION'],
        ], $fkRows),
        'Composite parcel-to-territory foreign key differs.',
    );

    $deleteRules = $pdo->query(<<<'SQL'
        SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME IN (
              'fk_active_dataset_dataset',
              'fk_import_territory_dataset',
              'fk_cadastral_territory_dataset',
              'fk_parcel_territory_dataset'
          )
        ORDER BY CONSTRAINT_NAME
        SQL)->fetchAll();
    assertSameValue(4, count($deleteRules), 'Required foreign keys are missing.');
    foreach ($deleteRules as $rule) {
        assertSameValue('RESTRICT', $rule['DELETE_RULE'], sprintf('%s must restrict deletes.', $rule['CONSTRAINT_NAME']));
        assertSameValue('RESTRICT', $rule['UPDATE_RULE'], sprintf('%s must restrict key updates.', $rule['CONSTRAINT_NAME']));
    }

    $transform = $pdo->query(<<<'SQL'
        SELECT
            ST_Longitude(transformed) AS longitude,
            ST_Latitude(transformed) AS latitude,
            ST_AsGeoJSON(transformed) AS geojson,
            ST_SRID(transformed) AS srid
        FROM (
            SELECT ST_Transform(
                ST_GeomFromText(
                    'POINT(-671984.1403374915 -1013081.1797817094)',
                    5514,
                    'axis-order=srid-defined'
                ),
                4326
            ) AS transformed
        ) AS control_point
        SQL)->fetch();
    assertTrue(abs((float) $transform['longitude'] - 15.3516) < 0.00001, '5514 to 4326 longitude is outside tolerance.');
    assertTrue(abs((float) $transform['latitude'] - 50.4372) < 0.00001, '5514 to 4326 latitude is outside tolerance.');
    assertSameValue(4326, (int) $transform['srid'], 'Transformed point has the wrong SRID.');
    $geojson = json_decode((string) $transform['geojson'], true, flags: JSON_THROW_ON_ERROR);
    assertTrue(abs((float) $geojson['coordinates'][0] - 15.3516) < 0.00001, 'GeoJSON first coordinate is not longitude.');
    assertTrue(abs((float) $geojson['coordinates'][1] - 50.4372) < 0.00001, 'GeoJSON second coordinate is not latitude.');

    $reverse = $pdo->query(<<<'SQL'
        SELECT ST_X(transformed) AS x, ST_Y(transformed) AS y, ST_SRID(transformed) AS srid
        FROM (
            SELECT ST_Transform(
                ST_GeomFromText('POINT(15.3516 50.4372)', 4326, 'axis-order=long-lat'),
                5514
            ) AS transformed
        ) AS control_point
        SQL)->fetch();
    assertTrue(abs((float) $reverse['x'] - (-671984.1403374915)) < 1.0, '4326 to 5514 easting is outside tolerance.');
    assertTrue(abs((float) $reverse['y'] - (-1013081.1797817094)) < 1.0, '4326 to 5514 northing is outside tolerance.');
    assertSameValue(5514, (int) $reverse['srid'], 'Reverse-transformed point has the wrong SRID.');

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM parcel');
    $pdo->exec('DELETE FROM cadastral_territory');
    $pdo->exec('DELETE FROM import_territory');
    $pdo->exec('DELETE FROM active_dataset');
    $pdo->exec('DELETE FROM dataset');

    $datasetA = insertDataset($pdo, 'fixture_a', 'ready');
    $datasetB = insertDataset($pdo, 'fixture_b', 'importing');

    $checkpoint = $pdo->prepare(<<<'SQL'
        INSERT INTO import_territory (dataset_id, ku_code, source_url)
        VALUES (:dataset_id, '123456', 'https://example.test/123456.zip')
        SQL);
    $checkpoint->execute(['dataset_id' => $datasetA]);
    expectDatabaseRejection(
        static fn () => $checkpoint->execute(['dataset_id' => $datasetA]),
        'duplicate dataset/KU import checkpoint',
    );
    $pdo->prepare(<<<'SQL'
        UPDATE import_territory
        SET attempt_count = attempt_count + 1, status = 'processing'
        WHERE dataset_id = :dataset_id AND ku_code = '123456'
        SQL)->execute(['dataset_id' => $datasetA]);
    assertSameValue('1', (string) $pdo->query(<<<SQL
        SELECT attempt_count FROM import_territory
        WHERE dataset_id = {$datasetA} AND ku_code = '123456'
        SQL)->fetchColumn(), 'Checkpoint retry did not update the unique row.');

    expectDatabaseRejection(
        static fn () => $pdo->exec(<<<'SQL'
            INSERT INTO import_territory (dataset_id, ku_code, source_url)
            VALUES (999999999, '654321', 'https://example.test/orphan.zip')
            SQL),
        'orphan import checkpoint',
    );

    $territoryA = insertTerritory($pdo, $datasetA, '123456', 'CZ.TERRITORY.A');
    $territoryB = insertTerritory($pdo, $datasetB, '654321', 'CZ.TERRITORY.B');
    expectDatabaseRejection(
        static fn () => insertTerritory($pdo, $datasetA, '123456', 'CZ.TERRITORY.DUPLICATE_KU'),
        'duplicate dataset/KU territory',
    );
    expectDatabaseRejection(
        static fn () => insertTerritory($pdo, $datasetA, '111111', 'CZ.TERRITORY.A'),
        'duplicate dataset/INSPIRE territory identity',
    );
    expectDatabaseRejection(
        static fn () => insertTerritory($pdo, 999999999, '111111', 'CZ.TERRITORY.ORPHAN'),
        'orphan cadastral territory',
    );

    insertParcel($pdo, $datasetA, $territoryA, 'CZ.PARCEL.INSIDE', $insideWkt);
    insertParcel($pdo, $datasetA, $territoryA, 'CZ.PARCEL.OUTSIDE', $outsideWkt);
    insertParcel($pdo, $datasetA, $territoryA, 'CZ.PARCEL.MBR_ONLY', $mbrOnlyWkt);
    insertParcel($pdo, $datasetB, $territoryB, 'CZ.PARCEL.B', $insideWkt);
    expectDatabaseRejection(
        static fn () => insertParcel($pdo, $datasetA, $territoryA, 'CZ.PARCEL.INSIDE', $insideWkt),
        'duplicate dataset/INSPIRE parcel identity',
    );

    expectDatabaseRejection(
        static fn () => insertParcel($pdo, $datasetB, $territoryA, 'CZ.PARCEL.CROSS_DATASET', $insideWkt),
        'parcel references a territory in another dataset',
    );
    expectDatabaseRejection(
        static fn () => $pdo->exec(<<<'SQL'
            INSERT INTO parcel (
                dataset_id, territory_id, inspire_id, label,
                national_cadastral_reference, area_m2, geom_native
            ) VALUES (
                NULL, NULL, 'CZ.PARCEL.NULL', 'null', 'null', 1,
                ST_GeomFromText(
                    'MULTIPOLYGON(((15.35 50.43, 15.36 50.43, 15.36 50.44, 15.35 50.44, 15.35 50.43)))',
                    4326,
                    'axis-order=long-lat'
                )
            )
            SQL),
        'null required relationship keys',
    );
    expectDatabaseRejection(
        static fn () => $pdo->prepare(<<<'SQL'
            INSERT INTO parcel (
                dataset_id, territory_id, inspire_id, label,
                national_cadastral_reference, area_m2, geom_native
            ) VALUES (
                :dataset_id, :territory_id, 'CZ.PARCEL.WRONG_SRID', 'wrong', 'wrong', 1,
                ST_GeomFromText(
                    'MULTIPOLYGON(((15.35 50.43, 15.36 50.43, 15.36 50.44, 15.35 50.44, 15.35 50.43)))',
                    4326,
                    'axis-order=long-lat'
                )
            )
            SQL)->execute(['dataset_id' => $datasetA, 'territory_id' => $territoryA]),
        'geometry with a non-5514 SRID',
    );
    expectDatabaseRejection(
        static fn () => $pdo->exec("INSERT INTO active_dataset (slot, dataset_id, activated_at) VALUES (2, {$datasetB}, CURRENT_TIMESTAMP(6))"),
        'second active-dataset slot',
    );

    $spatialStatement = $pdo->prepare(<<<'SQL'
        SELECT inspire_id
        FROM parcel
        WHERE dataset_id = :dataset_id
          AND MBRIntersects(
              geom_native,
              ST_GeomFromText(:mbr_query_wkt, 5514, 'axis-order=srid-defined')
          )
          AND ST_Intersects(
              geom_native,
              ST_GeomFromText(:exact_query_wkt, 5514, 'axis-order=srid-defined')
          )
        ORDER BY inspire_id
        SQL);
    $spatialStatement->execute([
        'dataset_id' => $datasetA,
        'mbr_query_wkt' => $queryEnvelopeWkt,
        'exact_query_wkt' => $queryEnvelopeWkt,
    ]);
    assertSameValue(['CZ.PARCEL.INSIDE'], $spatialStatement->fetchAll(PDO::FETCH_COLUMN), 'Exact spatial query returned the wrong parcels.');

    $mbrStatement = $pdo->prepare(<<<'SQL'
        SELECT inspire_id
        FROM parcel
        WHERE dataset_id = :dataset_id
          AND MBRIntersects(
              geom_native,
              ST_GeomFromText(:query_wkt, 5514, 'axis-order=srid-defined')
          )
        ORDER BY inspire_id
        SQL);
    $mbrStatement->execute(['dataset_id' => $datasetA, 'query_wkt' => $queryEnvelopeWkt]);
    assertSameValue(
        ['CZ.PARCEL.INSIDE', 'CZ.PARCEL.MBR_ONLY'],
        $mbrStatement->fetchAll(PDO::FETCH_COLUMN),
        'MBR fixture did not exercise the exact-predicate false positive.',
    );
    assertSameValue('5514', (string) $pdo->query(<<<SQL
        SELECT DISTINCT ST_SRID(geom_native) FROM parcel WHERE dataset_id = {$datasetA}
        SQL)->fetchColumn(), 'Stored parcel geometry has the wrong SRID.');

    for ($index = 0; $index < 512; ++$index) {
        insertParcel(
            $pdo,
            $datasetA,
            $territoryA,
            sprintf('CZ.PLAN.%04d', $index),
            $outsideWkt,
        );
    }

    $planStatement = $pdo->prepare(<<<'SQL'
        EXPLAIN FORMAT=JSON
        SELECT id
        FROM parcel
        WHERE MBRIntersects(
            geom_native,
            ST_GeomFromText(:query_wkt, 5514, 'axis-order=srid-defined')
        )
        SQL);
    $planStatement->execute(['query_wkt' => $queryEnvelopeWkt]);
    $plan = (string) $planStatement->fetchColumn();
    $decodedPlan = json_decode($plan, true, flags: JSON_THROW_ON_ERROR);
    assertSameValue(
        'sp_parcel_geom_native',
        $decodedPlan['query_block']['table']['key'] ?? null,
        'BBOX query plan does not use the parcel spatial index.',
    );
    $pdo->exec("DELETE FROM parcel WHERE inspire_id LIKE 'CZ.PLAN.%'");

    $pdo->exec("INSERT INTO active_dataset (slot, dataset_id, activated_at) VALUES (1, {$datasetA}, CURRENT_TIMESTAMP(6))");
    $activeCountSql = <<<'SQL'
        SELECT COUNT(*)
        FROM active_dataset AS active
        INNER JOIN dataset ON dataset.id = active.dataset_id AND dataset.status = 'ready'
        INNER JOIN parcel ON parcel.dataset_id = dataset.id
        WHERE active.slot = 1
        SQL;
    assertSameValue('3', (string) $pdo->query($activeCountSql)->fetchColumn(), 'Active dataset A is not isolated.');
    $pdo->exec("UPDATE active_dataset SET dataset_id = {$datasetB}, activated_at = CURRENT_TIMESTAMP(6) WHERE slot = 1");
    assertSameValue('0', (string) $pdo->query($activeCountSql)->fetchColumn(), 'Importing dataset became queryable.');
    $pdo->exec("UPDATE dataset SET status = 'ready' WHERE id = {$datasetB}");
    assertSameValue('1', (string) $pdo->query($activeCountSql)->fetchColumn(), 'Active dataset B is not isolated.');

    expectDatabaseRejection(
        static fn () => $pdo->exec("DELETE FROM cadastral_territory WHERE id = {$territoryA}"),
        'referenced territory deletion',
    );
    expectDatabaseRejection(
        static fn () => $pdo->exec("DELETE FROM dataset WHERE id = {$datasetB}"),
        'active/referenced dataset deletion',
    );

    $pdo->rollBack();

    printf("Database verification passed on MySQL %s.\n", $serverVersion);
    echo "Verified migrations, SRS 4326/5514, bidirectional transform, GeoJSON [lng, lat],\n";
    echo "SRID-restricted geometry, spatial/B-tree indexes, indexed BBOX query,\n";
    echo "active dataset isolation, checkpoint uniqueness and S3 foreign-key invariants.\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, sprintf("Database verification failed: %s\n", $exception->getMessage()));
    exit(1);
}
