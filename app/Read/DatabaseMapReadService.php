<?php

declare(strict_types=1);

namespace App\Read;

use App\Api\ApiException;
use App\Api\BoundingBox;
use App\Api\MapApiConfig;
use App\Api\MapReadService;
use App\Geo\ViewportEnvelopeBuilder;
use PDO;
use Throwable;

final readonly class DatabaseMapReadService implements MapReadService
{
    private MapReadRepository $repository;
    private ViewportEnvelopeBuilder $envelopes;

    public function __construct(
        private PDO $pdo,
        private MapApiConfig $config,
    ) {
        $this->repository = new MapReadRepository($pdo);
        $this->envelopes = new ViewportEnvelopeBuilder(
            $pdo,
            MapApiConfig::EDGE_SAMPLE_STEP_DEGREES,
            MapApiConfig::QUERY_MARGIN_METRES,
        );
    }

    public function cadastralTerritories(BoundingBox $boundingBox): array
    {
        return $this->read(function (int $datasetId) use ($boundingBox): array {
            $relevant = $boundingBox->intersection($this->config->districtBounds);
            if ($relevant === null) {
                return self::featureCollection([]);
            }
            $envelope = $this->envelopes->build($relevant);
            $rows = $this->repository->cadastralTerritories(
                $datasetId,
                $envelope->polygonWkt(),
                MapApiConfig::TERRITORY_FEATURE_LIMIT + 1,
            );
            if (count($rows) > MapApiConfig::TERRITORY_FEATURE_LIMIT) {
                throw new \RuntimeException('Active dataset contains more cadastral territories than the API contract permits.');
            }

            return self::featureCollection(array_map(static fn (array $row): array => [
                'type' => 'Feature',
                'id' => (string) $row['ku_code'],
                'properties' => [
                    'ku_code' => (string) $row['ku_code'],
                    'name' => (string) $row['name'],
                    'parcel_count' => (int) $row['parcel_count'],
                ],
                'geometry' => MapReadRepository::decodeGeometry((string) $row['geometry_json']),
            ], $rows));
        });
    }

    public function parcels(BoundingBox $boundingBox): array
    {
        return $this->read(function (int $datasetId) use ($boundingBox): array {
            $relevant = $boundingBox->intersection($this->config->districtBounds);
            if ($relevant === null) {
                return self::featureCollection([]);
            }
            $envelope = $this->envelopes->build($relevant);
            $rows = $this->repository->parcels(
                $datasetId,
                $envelope->polygonWkt(),
                MapApiConfig::PARCEL_FEATURE_LIMIT + 1,
            );
            if (count($rows) > MapApiConfig::PARCEL_FEATURE_LIMIT) {
                throw new ApiException(409, 'too_dense', 'Viewport exceeds the parcel representation limit.');
            }

            return self::featureCollection(array_map(static fn (array $row): array => [
                'type' => 'Feature',
                'id' => (string) $row['inspire_id'],
                'properties' => ['label' => (string) $row['label']],
                'geometry' => MapReadRepository::decodeGeometry((string) $row['geometry_json']),
            ], $rows));
        });
    }

    public function parcelDetail(string $inspireId): ?array
    {
        return $this->read(function (int $datasetId) use ($inspireId): ?array {
            $row = $this->repository->parcelDetail($datasetId, $inspireId);
            if ($row === null) {
                return null;
            }

            return [
                'inspire_id' => (string) $row['inspire_id'],
                'label' => (string) $row['label'],
                'area_m2' => (float) $row['area_m2'],
                'national_cadastral_reference' => (string) $row['national_cadastral_reference'],
                'cadastral_territory' => [
                    'ku_code' => (string) $row['ku_code'],
                    'name' => (string) $row['territory_name'],
                ],
            ];
        });
    }

    /** @template T @param callable(int): T $operation @return T */
    private function read(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Map API cannot start inside another transaction.');
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $this->pdo->beginTransaction();
        try {
            $datasetId = $this->repository->activeDatasetId();
            if ($datasetId === null) {
                throw new ApiException(503, 'dataset_unavailable', 'No active ready dataset is available.');
            }
            $result = $operation($datasetId);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param list<array<string, mixed>> $features @return array{type: string, features: list<array<string, mixed>>} */
    private static function featureCollection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
