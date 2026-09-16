<?php

declare(strict_types=1);

namespace App\Http;

final readonly class JsonResponse
{
    /** @param array<string, mixed> $body @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $body,
        public array $headers = [],
    ) {
    }

    public function emit(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode(
            $this->body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
