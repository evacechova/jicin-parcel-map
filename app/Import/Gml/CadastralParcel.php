<?php

declare(strict_types=1);

namespace App\Import\Gml;

final readonly class CadastralParcel
{
    public function __construct(
        public string $gmlId,
        public string $localId,
        public string $identifierNamespace,
        public string $label,
        public string $nationalCadastralReference,
        public string $areaSquareMetres,
        public string $zoningKuCode,
        public ?string $beginLifespanVersion,
        public ?string $validFrom,
        public GeometryValue $geometry,
        public ?string $referencePointWkt,
    ) {
    }
}
