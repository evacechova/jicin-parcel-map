<?php

declare(strict_types=1);

namespace App\Import\Download;

interface DownloadTransport
{
    public function fetch(string $url, string $destination): DownloadAttemptResult;
}
