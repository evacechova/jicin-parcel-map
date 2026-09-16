<?php

declare(strict_types=1);

namespace App\Import\Database;

use App\Import\Gml\CadastralParcel;
use App\Import\Gml\CadastralZoning;
use App\Import\ImportException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;

final class CadastralWriteRepository
{
    private readonly PDOStatement $territoryInsert;
    private readonly PDOStatement $parcelInsert;

    public function __construct(private readonly PDO $pdo)
    {
        $this->territoryInsert = $pdo->prepare(<<<'SQL'
            INSERT INTO cadastral_territory (
                dataset_id, ku_code, name, inspire_id,
                source_valid_from, source_begin_lifespan_version,
                geom_native, reference_point_native
            ) VALUES (
                :dataset_id, :ku_code, :name, :inspire_id,
                :source_valid_from, :source_begin_lifespan_version,
                ST_GeomFromText(:geom_wkt, 5514, 'axis-order=srid-defined'),
                ST_GeomFromText(:reference_point_wkt, 5514, 'axis-order=srid-defined')
            )
            SQL);
        $this->parcelInsert = $pdo->prepare(<<<'SQL'
            INSERT INTO parcel (
                dataset_id, territory_id, inspire_id, label,
                national_cadastral_reference, area_m2,
                geom_native, reference_point_native,
                source_valid_from, source_begin_lifespan_version
            ) VALUES (
                :dataset_id, :territory_id, :inspire_id, :label,
                :national_reference, :area_m2,
                ST_GeomFromText(:geom_wkt, 5514, 'axis-order=srid-defined'),
                ST_GeomFromText(:reference_point_wkt, 5514, 'axis-order=srid-defined'),
                :source_valid_from, :source_begin_lifespan_version
            )
            SQL);
    }

    public function lockImportableCheckpoint(int $datasetId, string $kuCode): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT import_dataset.status AS dataset_status, checkpoint.status AS checkpoint_status
            FROM dataset AS import_dataset
            INNER JOIN import_territory AS checkpoint ON checkpoint.dataset_id = import_dataset.id
            WHERE import_dataset.id = :dataset_id AND checkpoint.ku_code = :ku_code
            FOR UPDATE
            SQL);
        $statement->execute(['dataset_id' => $datasetId, 'ku_code' => $kuCode]);
        $row = $statement->fetch();
        if ($row === false || $row['dataset_status'] !== 'importing' || $row['checkpoint_status'] !== 'processing') {
            throw new ImportException('checkpoint_not_importable', 'Checkpoint is not processing in an importing dataset.');
        }
    }

    public function insertTerritory(int $datasetId, CadastralZoning $zoning): int
    {
        $this->territoryInsert->execute([
            'dataset_id' => $datasetId,
            'ku_code' => $zoning->kuCode,
            'name' => $zoning->label,
            'inspire_id' => $zoning->localId,
            'source_valid_from' => self::databaseDate($zoning->validFrom),
            'source_begin_lifespan_version' => self::databaseDate($zoning->beginLifespanVersion),
            'geom_wkt' => $zoning->geometry->wkt,
            'reference_point_wkt' => $zoning->referencePointWkt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function insertParcel(int $datasetId, int $territoryId, CadastralParcel $parcel): void
    {
        $this->parcelInsert->execute([
            'dataset_id' => $datasetId,
            'territory_id' => $territoryId,
            'inspire_id' => $parcel->localId,
            'label' => $parcel->label,
            'national_reference' => $parcel->nationalCadastralReference,
            'area_m2' => $parcel->areaSquareMetres,
            'geom_wkt' => $parcel->geometry->wkt,
            'reference_point_wkt' => $parcel->referencePointWkt,
            'source_valid_from' => self::databaseDate($parcel->validFrom),
            'source_begin_lifespan_version' => self::databaseDate($parcel->beginLifespanVersion),
        ]);
    }

    public function completeTerritory(int $datasetId, string $kuCode, int $parcelCount): void
    {
        $checkpoint = $this->pdo->prepare(<<<'SQL'
            UPDATE import_territory
            SET status = 'imported', parcel_count = :parcel_count,
                error_code = NULL, error_message = NULL
            WHERE dataset_id = :dataset_id AND ku_code = :ku_code AND status = 'processing'
            SQL);
        $checkpoint->execute([
            'parcel_count' => $parcelCount,
            'dataset_id' => $datasetId,
            'ku_code' => $kuCode,
        ]);

        $dataset = $this->pdo->prepare(<<<'SQL'
            UPDATE dataset
            SET territory_count = territory_count + 1,
                parcel_count = parcel_count + :parcel_count
            WHERE id = :dataset_id AND status = 'importing'
            SQL);
        $dataset->execute(['parcel_count' => $parcelCount, 'dataset_id' => $datasetId]);

        if ($checkpoint->rowCount() !== 1 || $dataset->rowCount() !== 1) {
            throw new ImportException('completion_state_error', 'Cannot complete the imported KÚ checkpoint.');
        }
    }

    private static function databaseDate(?string $sourceDate): ?string
    {
        if ($sourceDate === null) {
            return null;
        }

        return (new DateTimeImmutable($sourceDate))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
