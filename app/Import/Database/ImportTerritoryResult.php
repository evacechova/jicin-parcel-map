<?php

declare(strict_types=1);

namespace App\Import\Database;

final readonly class ImportTerritoryResult
{
    public function __construct(
        public int $datasetId,
        public int $territoryId,
        public string $kuCode,
        public int $parcelCount,
        public string $sourceChecksum,
        public int $sourceSizeBytes,
    ) {
    }
}
