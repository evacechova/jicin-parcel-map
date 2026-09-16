<?php

declare(strict_types=1);

namespace App\Import\Gml;

use App\Import\ImportException;
use App\Import\Source\GmlZipArchive;
use DateTimeImmutable;
use Generator;
use XMLReader;

final class GmlStreamParser
{
    private const CP_NS = 'http://inspire.ec.europa.eu/schemas/cp/4.0';
    private const BASE_NS = 'http://inspire.ec.europa.eu/schemas/base/3.3';
    private const GML_NS = 'http://www.opengis.net/gml/3.2';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
    private const SRS_5514 = 'http://www.opengis.net/def/crs/EPSG/0/5514';
    private const MAX_SCALAR_BYTES = 4_096;
    private const MAX_COORDINATE_TEXT_BYTES = 32 * 1024 * 1024;
    private const MAX_COORDINATES_PER_FEATURE = 1_000_000;

    public function readZoning(GmlZipArchive $source): CadastralZoning
    {
        [$reader, $previousErrorMode] = $this->open($source);
        $zoning = null;
        $rootSeen = false;

        try {
            while ($this->advance($reader)) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 0) {
                    $this->assertRootElement($reader, $rootSeen);
                    $rootSeen = true;
                }
                if ($this->isElement($reader, self::CP_NS, 'CadastralZoning')) {
                    if ($zoning !== null) {
                        throw new ImportException('multiple_zoning_features', 'GML contains more than one CadastralZoning feature.');
                    }
                    $zoning = $this->parseZoning($reader);
                }
            }
            $this->assertWellFormed();
            if (!$rootSeen) {
                throw new ImportException('invalid_gml_root', 'GML does not contain the expected SpatialDataSet root.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if ($zoning === null) {
            throw new ImportException('missing_zoning_feature', 'GML does not contain a CadastralZoning feature.');
        }

        return $zoning;
    }

    /** @return Generator<int, CadastralParcel> */
    public function parcels(GmlZipArchive $source): Generator
    {
        [$reader, $previousErrorMode] = $this->open($source);
        $rootSeen = false;

        try {
            while ($this->advance($reader)) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 0) {
                    $this->assertRootElement($reader, $rootSeen);
                    $rootSeen = true;
                }
                if ($this->isElement($reader, self::CP_NS, 'CadastralParcel')) {
                    yield $this->parseParcel($reader);
                }
            }
            $this->assertWellFormed();
            if (!$rootSeen) {
                throw new ImportException('invalid_gml_root', 'GML does not contain the expected SpatialDataSet root.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /** @return array{XMLReader, bool} */
    private function open(GmlZipArchive $source): array
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader();

        if (!$reader->open($source->streamUri(), null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors($previousErrorMode);
            throw new ImportException('gml_open_error', 'Cannot open the GML stream inside the ZIP.');
        }

        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        return [$reader, $previousErrorMode];
    }

    private function parseZoning(XMLReader $reader): CadastralZoning
    {
        $depth = $reader->depth;
        $gmlId = $this->requiredAttribute($reader, 'id', self::GML_NS, 'CadastralZoning gml:id');
        $identifier = null;
        $label = null;
        $kuCode = null;
        $begin = null;
        $validFrom = null;
        $validFromSeen = false;
        $geometry = null;
        $referencePoint = null;

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->namespaceURI !== self::CP_NS) {
                continue;
            }

            match ($reader->localName) {
                'beginLifespanVersion' => $begin = $this->once($begin, $this->readDate($reader), 'beginLifespanVersion'),
                'geometry' => $geometry = $this->once($geometry, $this->parseGeometry($reader), 'geometry'),
                'inspireId' => $identifier = $this->once($identifier, $this->parseIdentifier($reader), 'inspireId'),
                'label' => $label = $this->once($label, $this->requiredText($reader, 'label'), 'label'),
                'nationalCadastalZoningReference' => $kuCode = $this->once(
                    $kuCode,
                    $this->requiredText($reader, 'nationalCadastalZoningReference'),
                    'nationalCadastalZoningReference',
                ),
                'referencePoint' => $referencePoint = $this->once(
                    $referencePoint,
                    $this->parseReferencePoint($reader),
                    'referencePoint',
                ),
                'validFrom' => $validFrom = $this->readNullableDateField($validFromSeen, $reader, 'validFrom'),
                default => null,
            };
        }

        if ($identifier === null || $label === null || $kuCode === null || $geometry === null) {
            throw new ImportException('missing_required_field', 'CadastralZoning is missing a required field.');
        }
        if (preg_match('/^[0-9]{6}$/D', $kuCode) !== 1 || $identifier['localId'] !== 'CZ.' . $kuCode) {
            throw new ImportException('zoning_identity_mismatch', 'CadastralZoning national reference and INSPIRE localId do not match.');
        }

        return new CadastralZoning(
            $gmlId,
            $identifier['localId'],
            $identifier['namespace'],
            $kuCode,
            $label,
            $begin,
            $validFrom,
            $geometry,
            $referencePoint,
        );
    }

    private function parseParcel(XMLReader $reader): CadastralParcel
    {
        $depth = $reader->depth;
        $gmlId = $this->requiredAttribute($reader, 'id', self::GML_NS, 'CadastralParcel gml:id');
        $identifier = null;
        $label = null;
        $nationalReference = null;
        $area = null;
        $zoningKuCode = null;
        $begin = null;
        $validFrom = null;
        $validFromSeen = false;
        $geometry = null;
        $referencePoint = null;

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->namespaceURI !== self::CP_NS) {
                continue;
            }

            match ($reader->localName) {
                'areaValue' => $area = $this->once($area, $this->readArea($reader), 'areaValue'),
                'beginLifespanVersion' => $begin = $this->once($begin, $this->readDate($reader), 'beginLifespanVersion'),
                'geometry' => $geometry = $this->once($geometry, $this->parseGeometry($reader), 'geometry'),
                'inspireId' => $identifier = $this->once($identifier, $this->parseIdentifier($reader), 'inspireId'),
                'label' => $label = $this->once($label, $this->requiredText($reader, 'label'), 'label'),
                'nationalCadastralReference' => $nationalReference = $this->once(
                    $nationalReference,
                    $this->requiredText($reader, 'nationalCadastralReference'),
                    'nationalCadastralReference',
                ),
                'referencePoint' => $referencePoint = $this->once(
                    $referencePoint,
                    $this->parseReferencePoint($reader),
                    'referencePoint',
                ),
                'validFrom' => $validFrom = $this->readNullableDateField($validFromSeen, $reader, 'validFrom'),
                'zoning' => $zoningKuCode = $this->once($zoningKuCode, $this->readZoningReference($reader), 'zoning'),
                default => null,
            };
        }

        if (
            $identifier === null
            || $label === null
            || $nationalReference === null
            || $area === null
            || $zoningKuCode === null
            || $geometry === null
        ) {
            throw new ImportException('missing_required_field', 'CadastralParcel is missing a required field.');
        }
        if (preg_match('/^CP\.[A-Za-z0-9._-]+$/D', $identifier['localId']) !== 1) {
            throw new ImportException('invalid_local_id', 'CadastralParcel INSPIRE localId has an unexpected format.');
        }
        if (!str_starts_with($nationalReference, $zoningKuCode . '-')) {
            throw new ImportException('parcel_identity_mismatch', 'Parcel national reference and zoning KÚ do not match.');
        }

        return new CadastralParcel(
            $gmlId,
            $identifier['localId'],
            $identifier['namespace'],
            $label,
            $nationalReference,
            $area,
            $zoningKuCode,
            $begin,
            $validFrom,
            $geometry,
            $referencePoint,
        );
    }

    /** @return array{localId: string, namespace: string} */
    private function parseIdentifier(XMLReader $reader): array
    {
        $depth = $reader->depth;
        $localId = null;
        $namespace = null;

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if (!$this->isElementNamespace($reader, self::BASE_NS)) {
                continue;
            }
            if ($reader->localName === 'localId') {
                $localId = $this->once($localId, $this->requiredText($reader, 'localId'), 'localId');
            } elseif ($reader->localName === 'namespace') {
                $namespace = $this->once($namespace, $this->requiredText($reader, 'namespace'), 'namespace');
            }
        }

        if ($localId === null || $namespace === null || strlen($localId) > 64) {
            throw new ImportException('invalid_inspire_identifier', 'INSPIRE identifier must contain localId and namespace.');
        }
        if ($namespace !== 'CZ-00025712-CUZK_CP') {
            throw new ImportException('unexpected_identifier_namespace', 'INSPIRE identifier uses an unexpected namespace.');
        }

        return ['localId' => $localId, 'namespace' => $namespace];
    }

    private function parseGeometry(XMLReader $reader): GeometryValue
    {
        $depth = $reader->depth;
        $geometry = null;

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if (!$this->isElementNamespace($reader, self::GML_NS)) {
                continue;
            }

            if ($reader->localName === 'Polygon') {
                $this->validateSrs($reader, true);
                $geometry = $this->once($geometry, $this->normalisePolygons([$this->parsePolygon($reader)]), 'surface geometry');
            } elseif ($reader->localName === 'MultiSurface') {
                $this->validateSrs($reader, true);
                $geometry = $this->once($geometry, $this->normalisePolygons($this->parseMultiSurface($reader)), 'surface geometry');
            }
        }

        if ($geometry === null) {
            throw new ImportException('unsupported_geometry', 'Geometry must contain gml:Polygon or gml:MultiSurface.');
        }

        return $geometry;
    }

    /** @return list<array{rings: list<string>, coordinates: int, interiors: int}> */
    private function parseMultiSurface(XMLReader $reader): array
    {
        $depth = $reader->depth;
        $polygons = [];

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if ($this->isElement($reader, self::GML_NS, 'Polygon')) {
                $this->validateSrs($reader, false);
                $polygons[] = $this->parsePolygon($reader);
            }
        }

        if ($polygons === []) {
            throw new ImportException('empty_geometry', 'gml:MultiSurface must contain at least one Polygon.');
        }

        return $polygons;
    }

    /** @return array{rings: list<string>, coordinates: int, interiors: int} */
    private function parsePolygon(XMLReader $reader): array
    {
        $depth = $reader->depth;
        $exterior = null;
        $interiors = [];
        $coordinateCount = 0;

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if (!$this->isElementNamespace($reader, self::GML_NS)) {
                continue;
            }
            if ($reader->localName === 'exterior') {
                $ring = $this->parseBoundary($reader);
                $exterior = $this->once($exterior, $ring, 'Polygon exterior');
                $coordinateCount += $ring['coordinates'];
            } elseif ($reader->localName === 'interior') {
                $ring = $this->parseBoundary($reader);
                $interiors[] = $ring;
                $coordinateCount += $ring['coordinates'];
            }
            if ($coordinateCount > self::MAX_COORDINATES_PER_FEATURE) {
                throw new ImportException('geometry_coordinate_limit', 'Geometry exceeds the coordinate-count limit.');
            }
        }

        if ($exterior === null) {
            throw new ImportException('invalid_polygon', 'Polygon is missing its exterior LinearRing.');
        }

        return [
            'rings' => array_merge([$exterior['wkt']], array_column($interiors, 'wkt')),
            'coordinates' => $coordinateCount,
            'interiors' => count($interiors),
        ];
    }

    /** @return array{wkt: string, coordinates: int} */
    private function parseBoundary(XMLReader $reader): array
    {
        $depth = $reader->depth;
        $ring = null;

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if ($this->isElement($reader, self::GML_NS, 'LinearRing')) {
                $ring = $this->once($ring, $this->parseLinearRing($reader), 'LinearRing');
            }
        }

        if ($ring === null) {
            throw new ImportException('unsupported_ring', 'Polygon boundary must contain one gml:LinearRing.');
        }

        return $ring;
    }

    /** @return array{wkt: string, coordinates: int} */
    private function parseLinearRing(XMLReader $reader): array
    {
        $depth = $reader->depth;
        $points = [];
        $hasPosList = false;

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if (!$this->isElementNamespace($reader, self::GML_NS)) {
                continue;
            }
            if ($reader->localName === 'posList') {
                if ($hasPosList || $points !== []) {
                    throw new ImportException('invalid_ring', 'LinearRing mixes or duplicates coordinate encodings.');
                }
                $this->validateDimension($reader);
                $points = $this->parseCoordinateSequence(
                    $this->readElementText($reader, self::MAX_COORDINATE_TEXT_BYTES),
                );
                $hasPosList = true;
            } elseif ($reader->localName === 'pos') {
                if ($hasPosList) {
                    throw new ImportException('invalid_ring', 'LinearRing mixes posList and pos coordinates.');
                }
                $this->validateDimension($reader);
                $position = $this->parseCoordinateSequence($this->readElementText($reader, self::MAX_SCALAR_BYTES));
                if (count($position) !== 1) {
                    throw new ImportException('invalid_coordinate', 'gml:pos must contain exactly one 2D position.');
                }
                $points[] = $position[0];
            }
        }

        if (
            count($points) < 4
            || (float) $points[0][0] !== (float) $points[array_key_last($points)][0]
            || (float) $points[0][1] !== (float) $points[array_key_last($points)][1]
        ) {
            throw new ImportException('unclosed_ring', 'LinearRing must contain at least four positions and be closed.');
        }

        return [
            'wkt' => '(' . implode(',', array_map(static fn (array $point): string => $point[0] . ' ' . $point[1], $points)) . ')',
            'coordinates' => count($points),
        ];
    }

    /** @return list<array{string, string}> */
    private function parseCoordinateSequence(string $text): array
    {
        $tokens = preg_split('/\s+/', trim($text));
        if ($tokens === false || $tokens === [''] || count($tokens) % 2 !== 0) {
            throw new ImportException('invalid_coordinate', 'Coordinate sequence must contain 2D positions.');
        }

        $points = [];
        for ($index = 0; $index < count($tokens); $index += 2) {
            $x = $this->validateNumber($tokens[$index]);
            $y = $this->validateNumber($tokens[$index + 1]);
            $points[] = [$x, $y];
        }

        return $points;
    }

    /** @param list<array{rings: list<string>, coordinates: int, interiors: int}> $polygons */
    private function normalisePolygons(array $polygons): GeometryValue
    {
        $coordinateCount = array_sum(array_column($polygons, 'coordinates'));
        if ($coordinateCount > self::MAX_COORDINATES_PER_FEATURE) {
            throw new ImportException('geometry_coordinate_limit', 'Geometry exceeds the coordinate-count limit.');
        }

        $wktPolygons = array_map(
            static fn (array $polygon): string => '(' . implode(',', $polygon['rings']) . ')',
            $polygons,
        );

        return new GeometryValue(
            'MULTIPOLYGON(' . implode(',', $wktPolygons) . ')',
            count($polygons),
            $coordinateCount,
            array_sum(array_column($polygons, 'interiors')),
        );
    }

    private function parseReferencePoint(XMLReader $reader): string
    {
        $depth = $reader->depth;
        $point = null;
        $pointElementSeen = false;

        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if ($this->isElement($reader, self::GML_NS, 'Point')) {
                if ($pointElementSeen) {
                    throw new ImportException('invalid_reference_point', 'Reference point contains multiple gml:Point elements.');
                }
                $pointElementSeen = true;
                $this->validateSrs($reader, true);
            } elseif ($this->isElement($reader, self::GML_NS, 'pos')) {
                $this->validateDimension($reader);
                $positions = $this->parseCoordinateSequence($this->readElementText($reader, self::MAX_SCALAR_BYTES));
                if (count($positions) !== 1) {
                    throw new ImportException('invalid_reference_point', 'Reference Point must contain one 2D position.');
                }
                $point = $this->once($point, 'POINT(' . $positions[0][0] . ' ' . $positions[0][1] . ')', 'reference point');
            }
        }

        if (!$pointElementSeen || $point === null) {
            throw new ImportException('invalid_reference_point', 'Reference point is missing gml:Point/gml:pos.');
        }

        return $point;
    }

    private function readArea(XMLReader $reader): string
    {
        if ($reader->getAttribute('uom') !== 'm2') {
            throw new ImportException('invalid_area_unit', 'CadastralParcel areaValue must use m2.');
        }
        $value = $this->requiredText($reader, 'areaValue');
        if (preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,2})?$/D', $value) !== 1) {
            throw new ImportException('invalid_area', 'CadastralParcel areaValue is not a supported square-metre decimal.');
        }

        return $value;
    }

    private function readZoningReference(XMLReader $reader): string
    {
        $href = $this->requiredAttribute($reader, 'href', self::XLINK_NS, 'zoning xlink:href');
        if (preg_match('/(?:[?&])Id=CZ\.([0-9]{6})(?:&|$)/D', $href, $matches) !== 1) {
            throw new ImportException('invalid_zoning_reference', 'Parcel zoning reference does not contain a ČÚZK CZ.<KÚ> identifier.');
        }

        return $matches[1];
    }

    private function readDate(XMLReader $reader): string
    {
        $value = $this->requiredText($reader, $reader->localName);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) !== 1) {
            throw new ImportException('invalid_source_date', 'Source date is not an ISO date-time with timezone.');
        }
        try {
            new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new ImportException('invalid_source_date', 'Source date is not a valid ISO date-time.', $exception);
        }

        return $value;
    }

    private function readNullableDateField(bool &$seen, XMLReader $reader, string $field): ?string
    {
        if ($seen) {
            throw new ImportException('duplicate_source_field', sprintf('Feature contains duplicate %s.', $field));
        }
        $seen = true;

        $nil = $reader->getAttributeNs('nil', self::XSI_NS);
        if ($nil === 'true' || $nil === '1') {
            return null;
        }

        return $this->readDate($reader);
    }

    private function validateSrs(XMLReader $reader, bool $required): void
    {
        $srs = $reader->getAttribute('srsName');
        if (($required && $srs === null) || ($srs !== null && $srs !== self::SRS_5514)) {
            throw new ImportException('unexpected_srid', 'Geometry must declare EPSG:5514.');
        }
        $this->validateDimension($reader);
    }

    private function validateDimension(XMLReader $reader): void
    {
        $dimension = $reader->getAttribute('srsDimension');
        if ($dimension !== null && $dimension !== '2') {
            throw new ImportException('unexpected_dimension', 'GML coordinates must have dimension 2.');
        }
    }

    private function validateNumber(string $value): string
    {
        if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value) !== 1) {
            throw new ImportException('invalid_coordinate', 'GML contains an invalid coordinate.');
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new ImportException('invalid_coordinate', 'GML contains a non-finite coordinate.');
        }

        return $value;
    }

    private function requiredText(XMLReader $reader, string $field): string
    {
        $value = trim($this->readElementText($reader, self::MAX_SCALAR_BYTES));
        if ($value === '') {
            throw new ImportException('missing_required_field', sprintf('%s must not be empty.', $field));
        }

        return $value;
    }

    private function readElementText(XMLReader $reader, int $limit): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $depth = $reader->depth;
        $text = '';
        while ($this->advance($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType === XMLReader::ELEMENT) {
                throw new ImportException('unexpected_nested_element', 'Text-only GML field contains a nested element.');
            }
            if (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE, XMLReader::WHITESPACE], true)) {
                $text .= $reader->value;
                if (strlen($text) > $limit) {
                    throw new ImportException('source_text_limit', 'GML text node exceeds the configured size limit.');
                }
            }
        }

        return $text;
    }

    private function requiredAttribute(XMLReader $reader, string $name, string $namespace, string $field): string
    {
        $value = $reader->getAttributeNs($name, $namespace);
        if ($value === null || $value === '' || strlen($value) > self::MAX_SCALAR_BYTES) {
            throw new ImportException('missing_required_attribute', sprintf('%s is missing or invalid.', $field));
        }

        return $value;
    }

    private function advance(XMLReader $reader): bool
    {
        $advanced = $reader->read();
        if ($advanced && in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
            throw new ImportException('unsafe_xml', 'DTD and entity nodes are not allowed in ČÚZK GML.');
        }

        return $advanced;
    }

    private function assertWellFormed(): void
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if ($errors !== []) {
            throw new ImportException('malformed_gml', 'The GML document is not well formed.');
        }
    }

    private function assertRootElement(XMLReader $reader, bool $alreadySeen): void
    {
        if ($alreadySeen || $reader->namespaceURI !== self::BASE_NS || $reader->localName !== 'SpatialDataSet') {
            throw new ImportException('invalid_gml_root', 'GML root must be base:SpatialDataSet.');
        }
    }

    private function isElement(XMLReader $reader, string $namespace, string $localName): bool
    {
        return $reader->nodeType === XMLReader::ELEMENT
            && $reader->namespaceURI === $namespace
            && $reader->localName === $localName;
    }

    private function isElementNamespace(XMLReader $reader, string $namespace): bool
    {
        return $reader->nodeType === XMLReader::ELEMENT && $reader->namespaceURI === $namespace;
    }

    /** @template T @param ?T $current @param T $value @return T */
    private function once(mixed $current, mixed $value, string $field): mixed
    {
        if ($current !== null) {
            throw new ImportException('duplicate_source_field', sprintf('Feature contains duplicate %s.', $field));
        }

        return $value;
    }
}
