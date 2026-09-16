<?php

declare(strict_types=1);

namespace App\Import\Database;

use App\Import\ImportException;
use App\Import\Scope\CadastralScope;
use PDO;

final class DatasetValidator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function validate(int $datasetId, CadastralScope $scope): DatasetValidationResult
    {
        $dataset = $this->fetchOne(
            <<<'SQL'
                SELECT country_code, provider, scope_code, native_srid, display_srid,
                       source_url, status, territory_count, parcel_count
                FROM dataset
                WHERE id = :dataset_id
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require($dataset !== false, 'Dataset does not exist.');
        $this->require(
            $dataset['country_code'] === 'CZ'
            && $dataset['provider'] === 'CUZK'
            && $dataset['scope_code'] === $scope->code
            && (int) $dataset['native_srid'] === 5514
            && (int) $dataset['display_srid'] === 4326
            && $dataset['source_url'] === $scope->sourceBaseUrl,
            'Dataset metadata does not match the configured scope.',
        );
        $this->require($dataset['status'] === 'importing', 'Dataset is not in the importing pre-activation state.');

        $expectedCodes = $scope->territoryCodes();
        $checkpoints = $this->fetchAll(
            <<<'SQL'
                SELECT ku_code, source_url, source_checksum, source_size_bytes,
                       retrieved_at, status, attempt_count, last_attempt_at,
                       last_http_status, parcel_count
                FROM import_territory
                WHERE dataset_id = :dataset_id
                ORDER BY ku_code
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require(count($checkpoints) === count($expectedCodes), 'Checkpoint count does not match the configured scope.');

        $checkpointCodes = [];
        $checkpointParcelCount = 0;
        $sourceSizeBytes = 0;
        foreach ($checkpoints as $checkpoint) {
            $kuCode = (string) $checkpoint['ku_code'];
            $checkpointCodes[] = $kuCode;
            $this->require($checkpoint['status'] === 'imported', sprintf('Checkpoint %s is not imported.', $kuCode));
            $this->require($checkpoint['source_url'] === $scope->downloadUrl($kuCode), sprintf('Checkpoint %s has an unexpected source URL.', $kuCode));
            $this->require(
                is_string($checkpoint['source_checksum'])
                && preg_match('/^[a-f0-9]{64}$/D', $checkpoint['source_checksum']) === 1
                && (int) $checkpoint['source_size_bytes'] > 0
                && $checkpoint['retrieved_at'] !== null
                && (int) $checkpoint['attempt_count'] > 0
                && $checkpoint['last_attempt_at'] !== null
                && (int) $checkpoint['last_http_status'] >= 200
                && (int) $checkpoint['last_http_status'] < 300,
                sprintf('Checkpoint %s is missing successful source diagnostics.', $kuCode),
            );
            $checkpointParcelCount += (int) $checkpoint['parcel_count'];
            $sourceSizeBytes += (int) $checkpoint['source_size_bytes'];
        }
        $this->require($checkpointCodes === $expectedCodes, 'Checkpoint KÚ codes do not exactly match the configured scope.');

        $territories = $this->fetchAll(
            <<<'SQL'
                SELECT ku_code, name, inspire_id
                FROM cadastral_territory
                WHERE dataset_id = :dataset_id
                ORDER BY ku_code
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require(count($territories) === count($expectedCodes), 'Stored territory count does not match the configured scope.');
        $territoryCodes = [];
        foreach ($territories as $territory) {
            $kuCode = (string) $territory['ku_code'];
            $territoryCodes[] = $kuCode;
            $this->require(
                isset($scope->territories[$kuCode])
                && $territory['name'] === $scope->territories[$kuCode]
                && $territory['inspire_id'] !== '',
                sprintf('Stored territory %s does not match the configured/source identity.', $kuCode),
            );
        }
        $this->require($territoryCodes === $expectedCodes, 'Stored KÚ codes do not exactly match the configured scope.');

        $physicalTerritoryCount = (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM cadastral_territory WHERE dataset_id = :dataset_id',
            ['dataset_id' => $datasetId],
        );
        $physicalParcelCount = (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM parcel WHERE dataset_id = :dataset_id',
            ['dataset_id' => $datasetId],
        );
        $this->require((int) $dataset['territory_count'] === $physicalTerritoryCount, 'Dataset territory_count differs from physical rows.');
        $this->require((int) $dataset['parcel_count'] === $physicalParcelCount, 'Dataset parcel_count differs from physical rows.');
        $this->require($checkpointParcelCount === $physicalParcelCount, 'Checkpoint parcel totals differ from physical rows.');

        $perTerritoryMismatches = (int) $this->fetchColumn(
            <<<'SQL'
                SELECT COUNT(*)
                FROM (
                    SELECT territory.id
                    FROM cadastral_territory AS territory
                    INNER JOIN import_territory AS checkpoint
                        ON checkpoint.dataset_id = territory.dataset_id
                       AND checkpoint.ku_code = territory.ku_code
                    LEFT JOIN parcel
                        ON parcel.dataset_id = territory.dataset_id
                       AND parcel.territory_id = territory.id
                    WHERE territory.dataset_id = :dataset_id
                    GROUP BY territory.id, checkpoint.parcel_count
                    HAVING checkpoint.parcel_count <> COUNT(parcel.id)
                ) AS mismatches
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require($perTerritoryMismatches === 0, 'A checkpoint parcel count differs from its stored territory rows.');

        $orphanParcels = (int) $this->fetchColumn(
            <<<'SQL'
                SELECT COUNT(*)
                FROM parcel
                LEFT JOIN cadastral_territory AS territory
                    ON territory.dataset_id = parcel.dataset_id
                   AND territory.id = parcel.territory_id
                WHERE parcel.dataset_id = :dataset_id AND territory.id IS NULL
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require($orphanParcels === 0, 'A parcel does not have a matching territory in the same dataset.');

        $identityCounts = $this->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) AS row_count,
                       COUNT(DISTINCT ku_code) AS ku_count,
                       COUNT(DISTINCT inspire_id) AS inspire_count
                FROM cadastral_territory
                WHERE dataset_id = :dataset_id
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require(
            (int) $identityCounts['row_count'] === (int) $identityCounts['ku_count']
            && (int) $identityCounts['row_count'] === (int) $identityCounts['inspire_count'],
            'Territory source identities are not unique.',
        );
        $uniqueParcels = (int) $this->fetchColumn(
            'SELECT COUNT(DISTINCT inspire_id) FROM parcel WHERE dataset_id = :dataset_id',
            ['dataset_id' => $datasetId],
        );
        $this->require($uniqueParcels === $physicalParcelCount, 'Parcel source identities are not unique.');

        $invalidTerritories = (int) $this->fetchColumn(
            <<<'SQL'
                SELECT COUNT(*)
                FROM cadastral_territory
                WHERE dataset_id = :dataset_id
                  AND (
                      ST_SRID(geom_native) <> 5514
                      OR ST_IsEmpty(geom_native) = 1
                      OR ST_IsValid(geom_native) <> 1
                      OR (reference_point_native IS NOT NULL AND ST_SRID(reference_point_native) <> 5514)
                  )
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require($invalidTerritories === 0, 'A territory geometry is empty, invalid or has the wrong SRID.');
        $invalidParcels = (int) $this->fetchColumn(
            <<<'SQL'
                SELECT COUNT(*)
                FROM parcel
                WHERE dataset_id = :dataset_id
                  AND (
                      ST_SRID(geom_native) <> 5514
                      OR ST_IsEmpty(geom_native) = 1
                      OR ST_IsValid(geom_native) <> 1
                      OR (reference_point_native IS NOT NULL AND ST_SRID(reference_point_native) <> 5514)
                  )
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require($invalidParcels === 0, 'A parcel geometry is empty, invalid or has the wrong SRID.');

        $boundsWkt = $scope->districtBounds4326->polygonWkt();
        $outsideTerritories = $this->outsideBoundsCount('cadastral_territory', $datasetId, $boundsWkt);
        $this->require($outsideTerritories === 0, 'A territory geometry lies outside DISTRICT_BOUNDS_4326.');
        $outsideParcels = $this->outsideBoundsCount('parcel', $datasetId, $boundsWkt);
        $this->require($outsideParcels === 0, 'A parcel geometry lies outside DISTRICT_BOUNDS_4326.');

        $report = [
            'valid' => true,
            'validated_at' => gmdate('c'),
            'scope' => $scope->code,
            'expected_territories' => count($expectedCodes),
            'checkpoint_count' => count($checkpoints),
            'territory_count' => $physicalTerritoryCount,
            'parcel_count' => $physicalParcelCount,
            'source_size_bytes' => $sourceSizeBytes,
            'invalid_territories' => $invalidTerritories,
            'invalid_parcels' => $invalidParcels,
            'outside_territories' => $outsideTerritories,
            'outside_parcels' => $outsideParcels,
            'district_bounds_4326' => $scope->districtBounds4326->toArray(),
        ];

        return new DatasetValidationResult(
            $datasetId,
            $physicalTerritoryCount,
            $physicalParcelCount,
            $sourceSizeBytes,
            $report,
        );
    }

    public function revalidateActivationState(
        int $datasetId,
        CadastralScope $scope,
        DatasetValidationResult $validated,
    ): void {
        $dataset = $this->fetchOne(
            <<<'SQL'
                SELECT status, territory_count, parcel_count
                FROM dataset
                WHERE id = :dataset_id
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require(
            $dataset !== false
            && $dataset['status'] === 'importing'
            && (int) $dataset['territory_count'] === $validated->territoryCount
            && (int) $dataset['parcel_count'] === $validated->parcelCount,
            'Candidate dataset state changed after complete validation.',
        );

        $expectedCodes = $scope->territoryCodes();
        $checkpoints = $this->fetchAll(
            <<<'SQL'
                SELECT ku_code, status, parcel_count, source_size_bytes
                FROM import_territory
                WHERE dataset_id = :dataset_id
                ORDER BY ku_code
                SQL,
            ['dataset_id' => $datasetId],
        );
        $this->require(count($checkpoints) === count($expectedCodes), 'Checkpoint set changed after complete validation.');
        $checkpointCodes = [];
        $parcelCount = 0;
        $sourceSizeBytes = 0;
        foreach ($checkpoints as $checkpoint) {
            $checkpointCodes[] = (string) $checkpoint['ku_code'];
            $this->require($checkpoint['status'] === 'imported', 'Checkpoint state changed after complete validation.');
            $parcelCount += (int) $checkpoint['parcel_count'];
            $sourceSizeBytes += (int) $checkpoint['source_size_bytes'];
        }
        $this->require($checkpointCodes === $expectedCodes, 'Checkpoint scope changed after complete validation.');
        $this->require(
            $parcelCount === $validated->parcelCount && $sourceSizeBytes === $validated->sourceSizeBytes,
            'Checkpoint totals changed after complete validation.',
        );

        $territoryCodes = array_map(
            static fn (array $row): string => (string) $row['ku_code'],
            $this->fetchAll(
                'SELECT ku_code FROM cadastral_territory WHERE dataset_id = :dataset_id ORDER BY ku_code',
                ['dataset_id' => $datasetId],
            ),
        );
        $this->require($territoryCodes === $expectedCodes, 'Stored territory scope changed after complete validation.');
        $physicalParcelCount = (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM parcel WHERE dataset_id = :dataset_id',
            ['dataset_id' => $datasetId],
        );
        $this->require($physicalParcelCount === $validated->parcelCount, 'Physical parcel count changed after complete validation.');
    }

    private function outsideBoundsCount(string $table, int $datasetId, string $boundsWkt): int
    {
        if (!in_array($table, ['cadastral_territory', 'parcel'], true)) {
            throw new \LogicException('Unexpected geometry table.');
        }
        $statement = $this->pdo->prepare(sprintf(
            <<<'SQL'
                SELECT COUNT(*)
                FROM %s
                WHERE dataset_id = :dataset_id
                  AND ST_Within(
                      ST_Transform(geom_native, 4326),
                      ST_GeomFromText(:bounds_wkt, 4326, 'axis-order=long-lat')
                  ) <> 1
                SQL,
            $table,
        ));
        $statement->execute(['dataset_id' => $datasetId, 'bounds_wkt' => $boundsWkt]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, int|string> $parameters */
    private function fetchOne(string $sql, array $parameters): array|false
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetch();
    }

    /** @param array<string, int|string> $parameters @return list<array<string, mixed>> */
    private function fetchAll(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /** @param array<string, int|string> $parameters */
    private function fetchColumn(string $sql, array $parameters): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new ImportException('dataset_validation_failed', $message);
        }
    }
}
