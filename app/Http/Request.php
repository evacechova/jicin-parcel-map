<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public string $queryString = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            (string) (parse_url($requestUri, PHP_URL_PATH) ?? '/'),
            (string) (parse_url($requestUri, PHP_URL_QUERY) ?? ''),
        );
    }
}
