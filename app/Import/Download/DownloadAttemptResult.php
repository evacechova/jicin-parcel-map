<?php

declare(strict_types=1);

namespace App\Import\Download;

final readonly class DownloadAttemptResult
{
    private function __construct(
        public ?int $httpStatus,
        public ?int $curlError,
        public ?int $retryAfterSeconds,
        public int $bytesWritten,
    ) {
    }

    public static function http(int $status, ?int $retryAfterSeconds, int $bytesWritten): self
    {
        return new self($status, null, $retryAfterSeconds, $bytesWritten);
    }

    public static function transportFailure(int $curlError, int $bytesWritten): self
    {
        return new self(null, $curlError, null, $bytesWritten);
    }
}
