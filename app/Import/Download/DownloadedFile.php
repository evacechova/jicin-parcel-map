<?php

declare(strict_types=1);

namespace App\Import\Download;

final readonly class DownloadedFile
{
    public function __construct(
        public string $path,
        public int $attemptCount,
        public int $httpStatus,
        public int $sizeBytes,
        public string $sha256,
    ) {
    }
}
