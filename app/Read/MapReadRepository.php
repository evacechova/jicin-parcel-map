<?php

declare(strict_types=1);

namespace App\Read;

use PDO;
use RuntimeException;

final readonly class MapReadRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function activeDatasetId(): ?int
    {
        $value = $this->pdo->query(<<<'SQL'
            SELECT dataset.id
            FROM active_dataset
            INNER JOIN dataset
                ON dataset.id = active_dataset.dataset_id
               AND dataset.status = 'ready'
            WHERE active_dataset.slot = 1
            SQL)->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** @return list<array<string, mixed>> */
    public function cadastralTerritories(int $datasetId, string $envelopeWkt, int $limit): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT
                territory.ku_code,
                territory.name,
                checkpoint.parcel_count,
                ST_AsGeoJSON(ST_Transform(territory.geom_native, 4326), 8) AS geometry_json
            FROM cadastral_territory AS territory FORCE INDEX (sp_cadastral_territory_geom_native)
            STRAIGHT_JOIN active_dataset AS active
                ON active.slot = 1
               AND active.dataset_id = territory.dataset_id
            STRAIGHT_JOIN dataset
                ON dataset.id = active.dataset_id
               AND dataset.status = 'ready'
            STRAIGHT_JOIN import_territory AS checkpoint
                ON checkpoint.dataset_id = territory.dataset_id
               AND checkpoint.ku_code = territory.ku_code
               AND checkpoint.status = 'imported'
            WHERE territory.dataset_id = :dataset_id
              AND MBRIntersects(
                    territory.geom_native,
                    ST_GeomFromText(:mbr_envelope_wkt, 5514, 'axis-order=srid-defined')
                  )
              AND ST_Intersects(
                    territory.geom_native,
                    ST_GeomFromText(:exact_envelope_wkt, 5514, 'axis-order=srid-defined')
                  )
            ORDER BY territory.ku_code
            LIMIT :feature_limit
            SQL);
        $statement->bindValue('dataset_id', $datasetId, PDO::PARAM_INT);
        $statement->bindValue('mbr_envelope_wkt', $envelopeWkt);
        $statement->bindValue('exact_envelope_wkt', $envelopeWkt);
        $statement->bindValue('feature_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function parcels(int $datasetId, string $envelopeWkt, int $limit): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT
                parcel.inspire_id,
                parcel.label,
                ST_AsGeoJSON(ST_Transform(parcel.geom_native, 4326), 8) AS geometry_json
            FROM parcel FORCE INDEX (sp_parcel_geom_native)
            STRAIGHT_JOIN active_dataset AS active
                ON active.slot = 1
               AND active.dataset_id = parcel.dataset_id
            STRAIGHT_JOIN dataset
                ON dataset.id = active.dataset_id
               AND dataset.status = 'ready'
            WHERE parcel.dataset_id = :dataset_id
              AND MBRIntersects(
                    parcel.geom_native,
                    ST_GeomFromText(:mbr_envelope_wkt, 5514, 'axis-order=srid-defined')
                  )
              AND ST_Intersects(
                    parcel.geom_native,
                    ST_GeomFromText(:exact_envelope_wkt, 5514, 'axis-order=srid-defined')
                  )
            ORDER BY parcel.inspire_id
            LIMIT :feature_limit
            SQL);
        $statement->bindValue('dataset_id', $datasetId, PDO::PARAM_INT);
        $statement->bindValue('mbr_envelope_wkt', $envelopeWkt);
        $statement->bindValue('exact_envelope_wkt', $envelopeWkt);
        $statement->bindValue('feature_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function parcelDetail(int $datasetId, string $inspireId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT
                parcel.inspire_id,
                parcel.label,
                parcel.area_m2,
                parcel.national_cadastral_reference,
                territory.ku_code,
                territory.name AS territory_name
            FROM active_dataset AS active
            INNER JOIN dataset
                ON dataset.id = active.dataset_id
               AND dataset.status = 'ready'
            INNER JOIN parcel
                ON parcel.dataset_id = dataset.id
               AND parcel.inspire_id = :inspire_id
            INNER JOIN cadastral_territory AS territory
                ON territory.dataset_id = parcel.dataset_id
               AND territory.id = parcel.territory_id
            WHERE active.slot = 1
              AND dataset.id = :dataset_id
            SQL);
        $statement->execute(['inspire_id' => $inspireId, 'dataset_id' => $datasetId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    public static function decodeGeometry(string $geometryJson): array
    {
        $geometry = json_decode($geometryJson, true, flags: JSON_THROW_ON_ERROR);
        if (
            !is_array($geometry)
            || ($geometry['type'] ?? null) !== 'MultiPolygon'
            || !isset($geometry['coordinates'])
            || !is_array($geometry['coordinates'])
        ) {
            throw new RuntimeException('Database returned an invalid GeoJSON geometry.');
        }

        return $geometry;
    }
}
