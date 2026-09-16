<?php

declare(strict_types=1);

namespace App\Import\Database;

final readonly class ActivationResult
{
    public function __construct(
        public int $datasetId,
        public ?int $previousDatasetId,
        public DatasetValidationResult $validation,
    ) {
    }
}
