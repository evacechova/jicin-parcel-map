<?php

declare(strict_types=1);

use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\TestDatabaseGuard;
use App\Geo\CadastralCrs;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();

$config = DatabaseConfig::fromEnvironment('TEST_DB_');
TestDatabaseGuard::assertSafeConfig($config);
$pdo = ConnectionFactory::create($config);
TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
CadastralCrs::verify($pdo);

$fixturePath = $root . '/tests/Fixtures/Crs/cuzk-jicin-control-points.json';
$fixtureJson = file_get_contents($fixturePath);
if ($fixtureJson === false) {
    throw new RuntimeException('Cannot read the ČÚZK CRS regression fixture.');
}
$fixture = json_decode($fixtureJson, true, flags: JSON_THROW_ON_ERROR);
if (
    ($fixture['source'] ?? null) !== 'https://services.cuzk.gov.cz/wfs/inspire-CP-wfs.asp'
    || !str_contains((string) ($fixture['description'] ?? ''), 'OSM is not a reference')
    || count($fixture['parcels'] ?? []) !== 5
) {
    throw new RuntimeException('ČÚZK CRS regression fixture provenance is incomplete.');
}

$forward = $pdo->prepare(<<<'SQL'
    SELECT ST_Longitude(transformed) AS longitude,
           ST_Latitude(transformed) AS latitude
    FROM (
        SELECT ST_Transform(
            ST_SRID(
                ST_GeomFromText(:native_wkt, 5514, 'axis-order=srid-defined'),
                1005514
            ),
            4326
        ) AS transformed
    ) AS result
    SQL);
$reverse = $pdo->prepare(<<<'SQL'
    SELECT ST_X(transformed) AS x, ST_Y(transformed) AS y
    FROM (
        SELECT ST_Transform(
            ST_GeomFromText(:public_wkt, 4326, 'axis-order=long-lat'),
            1005514
        ) AS transformed
    ) AS result
    SQL);
$roundTrip = $pdo->prepare(<<<'SQL'
    SELECT ST_X(transformed) AS x, ST_Y(transformed) AS y
    FROM (
        SELECT ST_Transform(
            ST_Transform(
                ST_SRID(
                    ST_GeomFromText(:native_wkt, 5514, 'axis-order=srid-defined'),
                    1005514
                ),
                4326
            ),
            1005514
        ) AS transformed
    ) AS result
    SQL);

$eastErrors = [];
$northErrors = [];
$forwardErrors = [];
$reverseErrors = [];
$roundTripErrors = [];
$count = 0;

foreach ($fixture['parcels'] as $parcel) {
    if (
        !is_string($parcel['inspire_id'] ?? null)
        || !is_string($parcel['ku_code'] ?? null)
        || !is_string($parcel['begin_lifespan_version'] ?? null)
        || (int) ($parcel['point_count'] ?? -1) !== count($parcel['points'] ?? [])
    ) {
        throw new RuntimeException('ČÚZK CRS regression parcel metadata is invalid.');
    }

    foreach ($parcel['points'] as $point) {
        if (!is_array($point) || count($point) !== 4) {
            throw new RuntimeException('ČÚZK CRS regression point must contain native X/Y and public longitude/latitude.');
        }
        [$nativeX, $nativeY, $expectedLongitude, $expectedLatitude] = array_map('floatval', $point);
        $nativeWkt = sprintf('POINT(%.12F %.12F)', $nativeX, $nativeY);
        $publicWkt = sprintf('POINT(%.12F %.12F)', $expectedLongitude, $expectedLatitude);

        $forward->execute(['native_wkt' => $nativeWkt]);
        $forwardPoint = $forward->fetch();
        [$east, $north, $distance] = publicErrorMetres(
            $expectedLongitude,
            $expectedLatitude,
            (float) $forwardPoint['longitude'],
            (float) $forwardPoint['latitude'],
        );
        $eastErrors[] = $east;
        $northErrors[] = $north;
        $forwardErrors[] = $distance;

        $reverse->execute(['public_wkt' => $publicWkt]);
        $reversePoint = $reverse->fetch();
        $reverseErrors[] = hypot((float) $reversePoint['x'] - $nativeX, (float) $reversePoint['y'] - $nativeY);

        $roundTrip->execute(['native_wkt' => $nativeWkt]);
        $roundTripPoint = $roundTrip->fetch();
        $roundTripErrors[] = hypot((float) $roundTripPoint['x'] - $nativeX, (float) $roundTripPoint['y'] - $nativeY);
        ++$count;
    }
}

if ($count !== 145) {
    throw new RuntimeException(sprintf('Expected 145 authoritative control points, got %d.', $count));
}

$meanEast = array_sum($eastErrors) / $count;
$meanNorth = array_sum($northErrors) / $count;
$systematicVector = hypot($meanEast, $meanNorth);
$forwardMax = max($forwardErrors);
$reverseMax = max($reverseErrors);
$roundTripMax = max($roundTripErrors);

printf("points=%d\n", $count);
printf(
    "forward mean=%.6f p95=%.6f max=%.6f mean_east=%.6f mean_north=%.6f systematic=%.6f m\n",
    array_sum($forwardErrors) / $count,
    percentile($forwardErrors, 0.95),
    $forwardMax,
    $meanEast,
    $meanNorth,
    $systematicVector,
);
printf(
    "reverse mean=%.6f p95=%.6f max=%.6f m\n",
    array_sum($reverseErrors) / $count,
    percentile($reverseErrors, 0.95),
    $reverseMax,
);
printf(
    "round_trip mean=%.9f p95=%.9f max=%.9f m\n",
    array_sum($roundTripErrors) / $count,
    percentile($roundTripErrors, 0.95),
    $roundTripMax,
);

if ($forwardMax > 0.50) {
    throw new RuntimeException(sprintf('Forward CRS regression exceeds 0.50 m: %.6f m.', $forwardMax));
}
if ($reverseMax > 0.50) {
    throw new RuntimeException(sprintf('Reverse CRS regression exceeds 0.50 m: %.6f m.', $reverseMax));
}
if ($systematicVector > 0.25) {
    throw new RuntimeException(sprintf('Systematic CRS error vector exceeds 0.25 m: %.6f m.', $systematicVector));
}
if ($roundTripMax > 0.01) {
    throw new RuntimeException(sprintf('CRS round-trip exceeds 0.01 m: %.9f m.', $roundTripMax));
}

/** @return array{float, float, float} */
function publicErrorMetres(
    float $expectedLongitude,
    float $expectedLatitude,
    float $actualLongitude,
    float $actualLatitude,
): array {
    $meanLatitude = deg2rad(($expectedLatitude + $actualLatitude) / 2.0);
    $east = deg2rad($actualLongitude - $expectedLongitude) * 6371008.8 * cos($meanLatitude);
    $north = deg2rad($actualLatitude - $expectedLatitude) * 6371008.8;

    return [$east, $north, hypot($east, $north)];
}

/** @param list<float> $values */
function percentile(array $values, float $quantile): float
{
    sort($values, SORT_NUMERIC);
    $index = (int) ceil(count($values) * $quantile) - 1;

    return $values[max(0, min(count($values) - 1, $index))];
}
