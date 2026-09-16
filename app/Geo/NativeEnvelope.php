<?php

declare(strict_types=1);

namespace App\Geo;

final readonly class NativeEnvelope
{
    public function __construct(
        public float $minX,
        public float $minY,
        public float $maxX,
        public float $maxY,
    ) {
    }

    public function polygonWkt(): string
    {
        return sprintf(
            'POLYGON((%.12F %.12F,%.12F %.12F,%.12F %.12F,%.12F %.12F,%.12F %.12F))',
            $this->minX,
            $this->minY,
            $this->maxX,
            $this->minY,
            $this->maxX,
            $this->maxY,
            $this->minX,
            $this->maxY,
            $this->minX,
            $this->minY,
        );
    }
}
