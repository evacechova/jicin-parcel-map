<?php

declare(strict_types=1);

namespace App\Api;

interface MapReadService
{
    /** @return array<string, mixed> */
    public function cadastralTerritories(BoundingBox $boundingBox): array;

    /** @return array<string, mixed> */
    public function parcels(BoundingBox $boundingBox): array;

    /** @return array<string, mixed>|null */
    public function parcelDetail(string $inspireId): ?array;
}
