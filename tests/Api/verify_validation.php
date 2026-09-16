<?php

declare(strict_types=1);

use App\Api\BoundingBox;
use App\Api\MapApi;
use App\Api\MapApiConfig;
use App\Api\MapReadService;
use App\Http\Request;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function validationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function validationAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
    }
}

final class ValidationService implements MapReadService
{
    public function cadastralTerritories(BoundingBox $boundingBox): array
    {
        return ['type' => 'FeatureCollection', 'features' => []];
    }

    public function parcels(BoundingBox $boundingBox): array
    {
        return ['type' => 'FeatureCollection', 'features' => []];
    }

    public function parcelDetail(string $inspireId): ?array
    {
        return $inspireId === 'CP.valid-_1' ? ['inspire_id' => $inspireId] : null;
    }
}

function validationResponse(MapApi $api, string $path, string $method = 'GET'): array
{
    $uri = parse_url($path);
    $response = $api->handle(new Request($method, (string) $uri['path'], (string) ($uri['query'] ?? '')));

    return [$response->status, $response->body, $response->headers];
}

$api = new MapApi(new ValidationService(), new MapApiConfig());

[$status, $body] = validationResponse($api, '/api/v1/parcels?bbox=15.30,50.40,15.31,50.41&zoom=17');
validationAssertSame(200, $status, 'Valid parcel request failed.');
validationAssertSame('FeatureCollection', $body['type'] ?? null, 'Valid parcel response differs.');

$invalidBboxes = [
    '', '15,50,16', 'a,50,16,51', 'NaN,50,16,51', 'INF,50,16,51',
    '16,50,15,51', '15,51,16,50', '-181,50,16,51', '15,-91,16,51',
    '15,50,181,51', '15,50,16,91', '15, 50,16,51', '1e1,50,16,51',
];
foreach ($invalidBboxes as $bbox) {
    [$status, $body] = validationResponse($api, '/api/v1/cadastral-territories?bbox=' . rawurlencode($bbox));
    validationAssertSame(400, $status, sprintf('Invalid BBOX %s was accepted.', var_export($bbox, true)));
    validationAssertSame('invalid_bbox', $body['error']['code'] ?? null, 'Invalid BBOX error code differs.');
}

foreach (['', '-1', '+17', '017', '17.0', '1e1', '23', 'NaN'] as $zoom) {
    $query = 'bbox=15.30,50.40,15.31,50.41' . ($zoom === '' ? '' : '&zoom=' . rawurlencode($zoom));
    [$status, $body] = validationResponse($api, '/api/v1/parcels?' . $query);
    validationAssertSame(400, $status, sprintf('Invalid zoom %s was accepted.', var_export($zoom, true)));
    validationAssertSame('invalid_zoom', $body['error']['code'] ?? null, 'Invalid zoom error code differs.');
}

[$status, $body] = validationResponse($api, '/api/v1/parcels?bbox=15.30,50.40,15.31,50.41&zoom=16');
validationAssertSame(422, $status, 'Low zoom status differs.');
validationAssertSame('zoom_too_low', $body['error']['code'] ?? null, 'Low zoom error differs.');

[$status, $body] = validationResponse($api, '/api/v1/parcels?bbox=14,50,16,51&zoom=17');
validationAssertSame(422, $status, 'Large BBOX status differs.');
validationAssertSame('bbox_too_large', $body['error']['code'] ?? null, 'Large BBOX error differs.');

foreach ([
    '/api/v1/parcels?bbox=15.3,50.4,15.4,50.5&bbox=15.3,50.4,15.4,50.5&zoom=17',
    '/api/v1/parcels?bbox%5B%5D=15.3,50.4,15.4,50.5&zoom=17',
    '/api/v1/parcels?bbox=15.3,50.4,15.4,50.5&zoom=17&extra=1',
    '/api/v1/parcels?bbox=15.3,50.4,15.4,50.5&zoom=17&',
] as $uri) {
    [$status] = validationResponse($api, $uri);
    validationAssertSame(400, $status, 'Malformed query was accepted.');
}

[$status, $body] = validationResponse($api, '/api/v1/parcels/CP.valid-_1');
validationAssertSame(200, $status, 'Valid parcel detail ID failed.');
validationAssertSame('CP.valid-_1', $body['data']['inspire_id'] ?? null, 'Parcel detail response differs.');

foreach (['CP%2Fbad', 'bad%20id', '%ZZ', str_repeat('a', 65)] as $id) {
    [$status, $body] = validationResponse($api, '/api/v1/parcels/' . $id);
    validationAssertSame(400, $status, 'Invalid parcel ID was accepted.');
    validationAssertSame('invalid_parcel_id', $body['error']['code'] ?? null, 'Invalid parcel ID error differs.');
}

[$status, $body] = validationResponse($api, '/api/v1/missing');
validationAssertSame(404, $status, 'Unknown route status differs.');
validationAssertSame('not_found', $body['error']['code'] ?? null, 'Unknown route error differs.');

[$status, $body, $headers] = validationResponse($api, '/api/v1/parcels?bbox=15.3,50.4,15.4,50.5&zoom=17', 'POST');
validationAssertSame(405, $status, 'Wrong method status differs.');
validationAssertSame('GET', $headers['Allow'] ?? null, 'Allow header differs.');
validationAssert(isset($body['error']['requestId']) && $body['error']['requestId'] !== '', 'Error request ID is missing.');

$failingApi = new MapApi(
    new class implements MapReadService {
        public function cadastralTerritories(BoundingBox $boundingBox): array
        {
            throw new RuntimeException('SQLSTATE fixture detail that must not leak');
        }

        public function parcels(BoundingBox $boundingBox): array
        {
            throw new RuntimeException('SQLSTATE fixture detail that must not leak');
        }

        public function parcelDetail(string $inspireId): ?array
        {
            throw new RuntimeException('SQLSTATE fixture detail that must not leak');
        }
    },
    new MapApiConfig(),
);
[$status, $body] = validationResponse($failingApi, '/api/v1/parcels?bbox=15.3,50.4,15.4,50.5&zoom=17');
validationAssertSame(500, $status, 'Controlled service failure status differs.');
validationAssertSame('internal_error', $body['error']['code'] ?? null, 'Controlled service failure code differs.');
validationAssert(!str_contains(json_encode($body, JSON_THROW_ON_ERROR), 'SQLSTATE'), 'Internal failure detail leaked to the client.');

echo "API validation verification passed.\n";
