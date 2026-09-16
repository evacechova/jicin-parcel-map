<?php

declare(strict_types=1);

namespace App\Api;

final class QueryParameters
{
    /** @var array<string, list<string>> */
    private array $values = [];

    public function __construct(string $queryString)
    {
        if ($queryString === '') {
            return;
        }

        foreach (explode('&', $queryString) as $part) {
            if ($part === '') {
                throw self::malformed();
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            if (!self::hasValidPercentEncoding($rawKey) || !self::hasValidPercentEncoding($rawValue)) {
                throw self::malformed();
            }
            $key = rawurldecode($rawKey);
            $value = rawurldecode($rawValue);
            if ($key === '' || str_contains($key, '[') || str_contains($key, ']')) {
                throw self::malformed();
            }
            $this->values[$key] ??= [];
            $this->values[$key][] = $value;
        }
    }

    /** @param list<string> $allowed */
    public function requireOnly(array $allowed): void
    {
        foreach (array_keys($this->values) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw self::malformed();
            }
        }
    }

    public function one(string $name, string $errorCode, string $message): string
    {
        $values = $this->values[$name] ?? [];
        if (count($values) !== 1) {
            throw new ApiException(400, $errorCode, $message);
        }

        return $values[0];
    }

    private static function hasValidPercentEncoding(string $value): bool
    {
        return preg_match('/%(?![0-9A-Fa-f]{2})/', $value) !== 1;
    }

    private static function malformed(): ApiException
    {
        return new ApiException(400, 'malformed_request', 'The request query string is malformed.');
    }
}
