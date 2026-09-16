<?php

declare(strict_types=1);

namespace App\Import\Database;

final readonly class DatasetValidationResult
{
    /** @param array<string, mixed> $report */
    public function __construct(
        public int $datasetId,
        public int $territoryCount,
        public int $parcelCount,
        public int $sourceSizeBytes,
        public array $report,
    ) {
    }
}
