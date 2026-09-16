<?php

declare(strict_types=1);

namespace App\Import\Database;

final readonly class FullImportResult
{
    public function __construct(
        public string $runId,
        public string $runDirectory,
        public int $datasetId,
        public int $territoryCount,
        public int $parcelCount,
        public int $downloadSizeBytes,
        public float $durationSeconds,
        public bool $artifactsRetained,
        public ?int $previousDatasetId,
    ) {
    }
}
