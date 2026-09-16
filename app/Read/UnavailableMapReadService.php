<?php

declare(strict_types=1);

namespace App\Read;

use App\Api\ApiException;
use App\Api\BoundingBox;
use App\Api\MapReadService;

final class UnavailableMapReadService implements MapReadService
{
    public function cadastralTerritories(BoundingBox $boundingBox): array
    {
        throw self::unavailable();
    }

    public function parcels(BoundingBox $boundingBox): array
    {
        throw self::unavailable();
    }

    public function parcelDetail(string $inspireId): ?array
    {
        throw self::unavailable();
    }

    private static function unavailable(): ApiException
    {
        return new ApiException(503, 'dataset_unavailable', 'The active dataset is temporarily unavailable.');
    }
}
