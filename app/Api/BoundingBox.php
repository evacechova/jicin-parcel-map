<?php

declare(strict_types=1);

namespace App\Api;

final readonly class BoundingBox
{
    public function __construct(
        public float $minLongitude,
        public float $minLatitude,
        public float $maxLongitude,
        public float $maxLatitude,
    ) {
    }

    public static function parse(string $value): self
    {
        $parts = explode(',', $value);
        if (count($parts) !== 4) {
            throw self::invalid();
        }
        foreach ($parts as $part) {
            if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $part) !== 1) {
                throw self::invalid();
            }
        }

        $numbers = array_map(static fn (string $part): float => (float) $part, $parts);
        if (array_any($numbers, static fn (float $number): bool => !is_finite($number))) {
            throw self::invalid();
        }
        [$minLongitude, $minLatitude, $maxLongitude, $maxLatitude] = $numbers;
        if (
            $minLongitude < -180.0
            || $maxLongitude > 180.0
            || $minLatitude < -90.0
            || $maxLatitude > 90.0
            || $minLongitude >= $maxLongitude
            || $minLatitude >= $maxLatitude
        ) {
            throw self::invalid();
        }

        return new self($minLongitude, $minLatitude, $maxLongitude, $maxLatitude);
    }

    public function enforceMaximumSpan(float $maxLongitudeSpan, float $maxLatitudeSpan): void
    {
        if (
            $this->maxLongitude - $this->minLongitude > $maxLongitudeSpan
            || $this->maxLatitude - $this->minLatitude > $maxLatitudeSpan
        ) {
            throw new ApiException(422, 'bbox_too_large', 'The requested bounding box is too large.');
        }
    }

    public function intersection(self $other): ?self
    {
        $minLongitude = max($this->minLongitude, $other->minLongitude);
        $minLatitude = max($this->minLatitude, $other->minLatitude);
        $maxLongitude = min($this->maxLongitude, $other->maxLongitude);
        $maxLatitude = min($this->maxLatitude, $other->maxLatitude);
        if ($minLongitude > $maxLongitude || $minLatitude > $maxLatitude) {
            return null;
        }

        return new self($minLongitude, $minLatitude, $maxLongitude, $maxLatitude);
    }

    private static function invalid(): ApiException
    {
        return new ApiException(
            400,
            'invalid_bbox',
            'The bbox parameter must contain four ordered WGS84 coordinates.',
        );
    }
}
