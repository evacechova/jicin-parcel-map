<?php

declare(strict_types=1);

namespace App\Import\Gml;

final readonly class GeometryValue
{
    public function __construct(
        public string $wkt,
        public int $polygonCount,
        public int $coordinateCount,
        public int $interiorRingCount,
    ) {
    }
}
