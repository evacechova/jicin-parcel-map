<?php

declare(strict_types=1);

namespace App\Api;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $publicMessage,
    ) {
        parent::__construct($publicMessage);
    }
}
