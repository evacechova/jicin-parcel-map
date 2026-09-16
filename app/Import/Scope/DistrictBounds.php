<?php

declare(strict_types=1);

namespace App\Import\Scope;

use App\Import\ImportException;

final readonly class DistrictBounds
{
    public function __construct(
        public float $minLongitude,
        public float $minLatitude,
        public float $maxLongitude,
        public float $maxLatitude,
    ) {
        if (
            !is_finite($minLongitude)
            || !is_finite($minLatitude)
            || !is_finite($maxLongitude)
            || !is_finite($maxLatitude)
            || $minLongitude < -180.0
            || $maxLongitude > 180.0
            || $minLatitude < -90.0
            || $maxLatitude > 90.0
            || $minLongitude >= $maxLongitude
            || $minLatitude >= $maxLatitude
        ) {
            throw new ImportException('invalid_district_bounds', 'The configured district bounds are invalid.');
        }
    }

    public function polygonWkt(): string
    {
        return sprintf(
            'POLYGON((%.12F %.12F,%.12F %.12F,%.12F %.12F,%.12F %.12F,%.12F %.12F))',
            $this->minLongitude,
            $this->minLatitude,
            $this->maxLongitude,
            $this->minLatitude,
            $this->maxLongitude,
            $this->maxLatitude,
            $this->minLongitude,
            $this->maxLatitude,
            $this->minLongitude,
            $this->minLatitude,
        );
    }

    /** @return array{min_longitude: float, min_latitude: float, max_longitude: float, max_latitude: float} */
    public function toArray(): array
    {
        return [
            'min_longitude' => $this->minLongitude,
            'min_latitude' => $this->minLatitude,
            'max_longitude' => $this->maxLongitude,
            'max_latitude' => $this->maxLatitude,
        ];
    }
}
