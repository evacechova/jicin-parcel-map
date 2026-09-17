<?php

declare(strict_types=1);

use App\Api\BoundingBox;
use App\Api\MapApiConfig;
use App\Database\ConnectionFactory;
use App\Database\DatabaseConfig;
use App\Database\TestDatabaseGuard;
use App\Geo\ViewportEnvelopeBuilder;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
Dotenv\Dotenv::createImmutable($root, '.env.test')->safeLoad();

function envelopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<array{float, float}> */
function envelopeReferencePoints(BoundingBox $box, float $step): array
{
    $xs = max(1, (int) ceil(($box->maxLongitude - $box->minLongitude) / $step));
    $ys = max(1, (int) ceil(($box->maxLatitude - $box->minLatitude) / $step));
    $points = [];
    for ($xIndex = 0; $xIndex <= $xs; ++$xIndex) {
        $x = $box->minLongitude + (($box->maxLongitude - $box->minLongitude) * ($xIndex / $xs));
        for ($yIndex = 0; $yIndex <= $ys; ++$yIndex) {
            if ($xIndex !== 0 && $xIndex !== $xs && $yIndex !== 0 && $yIndex !== $ys) {
                continue;
            }
            $y = $box->minLatitude + (($box->maxLatitude - $box->minLatitude) * ($yIndex / $ys));
            $points[] = [$x, $y];
        }
    }

    return $points;
}

/** @param list<array{float, float}> $points @return array{float, float, float, float} */
function transformedExtents(PDO $pdo, array $points): array
{
    $minX = INF;
    $minY = INF;
    $maxX = -INF;
    $maxY = -INF;
    foreach (array_chunk($points, 1500) as $chunk) {
        $wkt = 'MULTIPOINT(' . implode(',', array_map(
            static fn (array $point): string => sprintf('(%.12F %.12F)', $point[0], $point[1]),
            $chunk,
        )) . ')';
        $statement = $pdo->prepare(<<<'SQL'
            SELECT ST_AsText(
                ST_SRID(
                    ST_Transform(
                        ST_GeomFromText(:points_wkt, 4326, 'axis-order=long-lat'),
                        1005514
                    ),
                    5514
                ),
                'axis-order=srid-defined'
            )
            SQL);
        $statement->execute(['points_wkt' => $wkt]);
        $transformed = (string) $statement->fetchColumn();
        preg_match_all(
            '/\(([+-]?[0-9]+(?:\.[0-9]+)?(?:[Ee][+-]?[0-9]+)?) ([+-]?[0-9]+(?:\.[0-9]+)?(?:[Ee][+-]?[0-9]+)?)\)/',
            $transformed,
            $matches,
            PREG_SET_ORDER,
        );
        envelopeAssert(count($matches) === count($chunk), 'Reference MULTIPOINT transformation returned an unexpected point count.');
        foreach ($matches as $match) {
            $x = (float) $match[1];
            $y = (float) $match[2];
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
    }

    return [$minX, $minY, $maxX, $maxY];
}

$config = DatabaseConfig::fromEnvironment('TEST_DB_');
TestDatabaseGuard::assertSafeConfig($config);
$pdo = ConnectionFactory::create($config);
TestDatabaseGuard::assertConnectedDatabase($pdo, $config);
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
envelopeAssert(str_starts_with($version, '8.4.'), sprintf('Spatial preflight requires MySQL 8.4; got %s.', $version));

$builder = new ViewportEnvelopeBuilder(
    $pdo,
    MapApiConfig::EDGE_SAMPLE_STEP_DEGREES,
    MapApiConfig::QUERY_MARGIN_METRES,
);
$cases = [
    'district' => new BoundingBox(14.80, 50.15, 15.95, 50.85),
    'small' => new BoundingBox(15.3001, 50.4002, 15.3117, 50.4093),
    'thin-horizontal' => new BoundingBox(14.90, 50.40000, 15.80, 50.40001),
    'thin-vertical' => new BoundingBox(15.40000, 50.20, 15.40001, 50.80),
    'point-contact' => new BoundingBox(14.80, 50.15, 14.80, 50.15),
    'line-contact' => new BoundingBox(14.80, 50.15, 14.80, 50.85),
];

$maximumEscape = 0.0;
foreach ($cases as $name => $box) {
    $envelope = $builder->build($box);
    $referenceStep = $name === 'district' ? 0.00025 : 0.00005;
    $reference = envelopeReferencePoints($box, $referenceStep);
    [$minX, $minY, $maxX, $maxY] = transformedExtents($pdo, $reference);
    envelopeAssert(
        $minX >= $envelope->minX
        && $minY >= $envelope->minY
        && $maxX <= $envelope->maxX
        && $maxY <= $envelope->maxY,
        sprintf('Expanded native envelope does not cover dense %s reference samples.', $name),
    );

    $coarseMinX = $envelope->minX + MapApiConfig::QUERY_MARGIN_METRES;
    $coarseMinY = $envelope->minY + MapApiConfig::QUERY_MARGIN_METRES;
    $coarseMaxX = $envelope->maxX - MapApiConfig::QUERY_MARGIN_METRES;
    $coarseMaxY = $envelope->maxY - MapApiConfig::QUERY_MARGIN_METRES;
    $escape = max(
        0.0,
        $coarseMinX - $minX,
        $coarseMinY - $minY,
        $maxX - $coarseMaxX,
        $maxY - $coarseMaxY,
    );
    $maximumEscape = max($maximumEscape, $escape);
}

envelopeAssert(
    $maximumEscape < MapApiConfig::QUERY_MARGIN_METRES / 10.0,
    sprintf('Observed unsampled boundary escape %.6f m leaves insufficient margin headroom.', $maximumEscape),
);

printf(
    "Spatial envelope preflight passed on MySQL %s: %.2f m margin, %.2f° coarse step, max observed dense-sample escape %.6f m.\n",
    $version,
    MapApiConfig::QUERY_MARGIN_METRES,
    MapApiConfig::EDGE_SAMPLE_STEP_DEGREES,
    $maximumEscape,
);
