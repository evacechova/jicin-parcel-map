<?php

declare(strict_types=1);

namespace App\Api;

final readonly class MapApiConfig
{
    public const int PARCEL_MIN_ZOOM = 17;
    public const int PARCEL_FEATURE_LIMIT = 2000;
    public const int TERRITORY_FEATURE_LIMIT = 240;
    public const float EDGE_SAMPLE_STEP_DEGREES = 0.01;
    public const float QUERY_MARGIN_METRES = 25.0;

    public BoundingBox $districtBounds;

    public function __construct()
    {
        $this->districtBounds = new BoundingBox(14.80, 50.15, 15.95, 50.85);
    }

    public function maxLongitudeSpan(): float
    {
        return $this->districtBounds->maxLongitude - $this->districtBounds->minLongitude;
    }

    public function maxLatitudeSpan(): float
    {
        return $this->districtBounds->maxLatitude - $this->districtBounds->minLatitude;
    }
}
