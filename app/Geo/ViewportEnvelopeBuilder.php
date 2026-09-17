<?php

declare(strict_types=1);

namespace App\Geo;

use App\Api\BoundingBox;
use PDO;
use RuntimeException;

final readonly class ViewportEnvelopeBuilder
{
    public function __construct(
        private PDO $pdo,
        private float $sampleStepDegrees,
        private float $marginMetres,
    ) {
        if ($sampleStepDegrees <= 0.0 || $marginMetres <= 0.0) {
            throw new \InvalidArgumentException('Spatial sampling step and query margin must be positive.');
        }
    }

    public function build(BoundingBox $boundingBox): NativeEnvelope
    {
        $points = $this->boundaryPoints($boundingBox);
        $pointWkt = 'MULTIPOINT(' . implode(',', array_map(
            static fn (array $point): string => sprintf('(%.12F %.12F)', $point[0], $point[1]),
            $points,
        )) . ')';

        $statement = $this->pdo->prepare(<<<'SQL'
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
        $statement->execute(['points_wkt' => $pointWkt]);
        $transformedWkt = $statement->fetchColumn();
        if (!is_string($transformedWkt)) {
            throw new RuntimeException('Viewport coordinate transformation returned no geometry.');
        }

        preg_match_all(
            '/\(([+-]?[0-9]+(?:\.[0-9]+)?(?:[Ee][+-]?[0-9]+)?) ([+-]?[0-9]+(?:\.[0-9]+)?(?:[Ee][+-]?[0-9]+)?)\)/',
            $transformedWkt,
            $matches,
            PREG_SET_ORDER,
        );
        if (count($matches) !== count($points)) {
            throw new RuntimeException('Viewport coordinate transformation returned an unexpected geometry.');
        }

        $xs = [];
        $ys = [];
        foreach ($matches as $match) {
            $x = (float) $match[1];
            $y = (float) $match[2];
            if (!is_finite($x) || !is_finite($y)) {
                throw new RuntimeException('Viewport coordinate transformation returned a non-finite coordinate.');
            }
            $xs[] = $x;
            $ys[] = $y;
        }

        return new NativeEnvelope(
            min($xs) - $this->marginMetres,
            min($ys) - $this->marginMetres,
            max($xs) + $this->marginMetres,
            max($ys) + $this->marginMetres,
        );
    }

    /** @return list<array{float, float}> */
    private function boundaryPoints(BoundingBox $box): array
    {
        if ($box->minLongitude === $box->maxLongitude && $box->minLatitude === $box->maxLatitude) {
            return [[$box->minLongitude, $box->minLatitude]];
        }
        if ($box->minLongitude === $box->maxLongitude) {
            return $this->linePoints(
                $box->minLongitude,
                $box->minLatitude,
                $box->maxLongitude,
                $box->maxLatitude,
            );
        }
        if ($box->minLatitude === $box->maxLatitude) {
            return $this->linePoints(
                $box->minLongitude,
                $box->minLatitude,
                $box->maxLongitude,
                $box->maxLatitude,
            );
        }

        return array_merge(
            $this->linePoints($box->minLongitude, $box->minLatitude, $box->maxLongitude, $box->minLatitude),
            $this->linePoints($box->maxLongitude, $box->minLatitude, $box->maxLongitude, $box->maxLatitude),
            $this->linePoints($box->maxLongitude, $box->maxLatitude, $box->minLongitude, $box->maxLatitude),
            $this->linePoints($box->minLongitude, $box->maxLatitude, $box->minLongitude, $box->minLatitude),
        );
    }

    /** @return list<array{float, float}> */
    private function linePoints(float $startX, float $startY, float $endX, float $endY): array
    {
        $segments = max(1, (int) ceil(max(abs($endX - $startX), abs($endY - $startY)) / $this->sampleStepDegrees));
        $points = [];
        for ($index = 0; $index <= $segments; ++$index) {
            $fraction = $index / $segments;
            $points[] = [
                $startX + (($endX - $startX) * $fraction),
                $startY + (($endY - $startY) * $fraction),
            ];
        }

        return $points;
    }
}
