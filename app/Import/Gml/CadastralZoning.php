<?php

declare(strict_types=1);

namespace App\Import\Gml;

final readonly class CadastralZoning
{
    public function __construct(
        public string $gmlId,
        public string $localId,
        public string $identifierNamespace,
        public string $kuCode,
        public string $label,
        public ?string $beginLifespanVersion,
        public ?string $validFrom,
        public GeometryValue $geometry,
        public ?string $referencePointWkt,
    ) {
    }
}
