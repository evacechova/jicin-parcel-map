<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use Throwable;

final readonly class MapApi
{
    /** @param callable(string, Throwable): void|null $errorLogger */
    public function __construct(
        private MapReadService $service,
        private MapApiConfig $config,
        private mixed $errorLogger = null,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $requestId = bin2hex(random_bytes(8));
        try {
            if ($request->method !== 'GET') {
                return $this->error(
                    new ApiException(405, 'method_not_allowed', 'Only GET requests are supported.'),
                    $requestId,
                    ['Allow' => 'GET'],
                );
            }

            if ($request->path === '/api/v1/cadastral-territories') {
                $query = new QueryParameters($request->queryString);
                $query->requireOnly(['bbox']);
                $bbox = BoundingBox::parse($query->one('bbox', 'invalid_bbox', 'The bbox parameter is required.'));
                $bbox->enforceMaximumSpan($this->config->maxLongitudeSpan(), $this->config->maxLatitudeSpan());

                return $this->success($this->service->cadastralTerritories($bbox), $requestId);
            }

            if ($request->path === '/api/v1/parcels') {
                $query = new QueryParameters($request->queryString);
                $query->requireOnly(['bbox', 'zoom']);
                $bbox = BoundingBox::parse($query->one('bbox', 'invalid_bbox', 'The bbox parameter is required.'));
                $zoom = self::parseZoom($query->one('zoom', 'invalid_zoom', 'The zoom parameter is required.'));
                $bbox->enforceMaximumSpan($this->config->maxLongitudeSpan(), $this->config->maxLatitudeSpan());
                if ($zoom < MapApiConfig::PARCEL_MIN_ZOOM) {
                    throw new ApiException(422, 'zoom_too_low', 'Parcel geometry is available only at a detailed zoom level.');
                }

                return $this->success($this->service->parcels($bbox), $requestId);
            }

            if (preg_match('#^/api/v1/parcels/([^/]+)$#D', $request->path, $matches) === 1) {
                $query = new QueryParameters($request->queryString);
                $query->requireOnly([]);
                if (preg_match('/%(?![0-9A-Fa-f]{2})/', $matches[1]) === 1) {
                    throw self::invalidParcelId();
                }
                $inspireId = rawurldecode($matches[1]);
                if (strlen($inspireId) > 64 || preg_match('/^[A-Za-z0-9._-]+$/D', $inspireId) !== 1) {
                    throw self::invalidParcelId();
                }
                $detail = $this->service->parcelDetail($inspireId);
                if ($detail === null) {
                    throw new ApiException(404, 'parcel_not_found', 'The parcel was not found in the active dataset.');
                }

                return $this->success(['data' => $detail], $requestId);
            }

            throw new ApiException(404, 'not_found', 'The requested API route was not found.');
        } catch (ApiException $exception) {
            return $this->error($exception, $requestId);
        } catch (Throwable $exception) {
            if (is_callable($this->errorLogger)) {
                ($this->errorLogger)($requestId, $exception);
            }

            return $this->error(
                new ApiException(500, 'internal_error', 'The request could not be completed.'),
                $requestId,
            );
        }
    }

    private static function parseZoom(string $value): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new ApiException(400, 'invalid_zoom', 'The zoom parameter must be an integer from 0 to 22.');
        }
        $zoom = (int) $value;
        if ($zoom < 0 || $zoom > 22) {
            throw new ApiException(400, 'invalid_zoom', 'The zoom parameter must be an integer from 0 to 22.');
        }

        return $zoom;
    }

    private static function invalidParcelId(): ApiException
    {
        return new ApiException(400, 'invalid_parcel_id', 'The parcel identifier is invalid.');
    }

    /** @param array<string, mixed> $body */
    private function success(array $body, string $requestId): JsonResponse
    {
        return new JsonResponse(200, $body, ['X-Request-Id' => $requestId]);
    }

    /** @param array<string, string> $headers */
    private function error(ApiException $exception, string $requestId, array $headers = []): JsonResponse
    {
        $headers['X-Request-Id'] = $requestId;

        return new JsonResponse($exception->status, [
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->publicMessage,
                'requestId' => $requestId,
            ],
        ], $headers);
    }
}
