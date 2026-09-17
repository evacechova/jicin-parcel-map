<?php

declare(strict_types=1);

namespace App\Import\Database;

use App\Geo\CadastralCrs;
use App\Import\ImportException;
use PDO;

final class ImportPreflight
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function verify(): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (!str_starts_with($version, '8.4.')) {
            throw new ImportException('unsupported_database', sprintf('Full import requires Oracle MySQL 8.4; connected server is %s.', $version));
        }

        $srids = (int) $this->pdo->query(<<<'SQL'
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS
            WHERE SRS_ID IN (5514, 4326, 1005514)
            SQL)->fetchColumn();
        if ($srids !== 3) {
            throw new ImportException('spatial_preflight_failed', 'Required SRIDs 5514, 4326 and 1005514 are not registered.');
        }
        try {
            CadastralCrs::verify($this->pdo);
        } catch (\RuntimeException $exception) {
            throw new ImportException('spatial_preflight_failed', $exception->getMessage());
        }

        $point = $this->pdo->query(<<<'SQL'
            SELECT ST_Longitude(transformed) AS longitude,
                   ST_Latitude(transformed) AS latitude
            FROM (
                SELECT ST_Transform(
                    ST_SRID(
                        ST_GeomFromText(
                            'POINT(-647087.71 -1027689.06)',
                            5514,
                            'axis-order=srid-defined'
                        ),
                        1005514
                    ),
                    4326
                ) AS transformed
            ) AS control_point
            SQL)->fetch();
        if (
            $point === false
            || abs((float) $point['longitude'] - 15.723937) > 0.000005
            || abs((float) $point['latitude'] - 50.334021) > 0.000005
        ) {
            throw new ImportException('spatial_preflight_failed', 'Application SRS 1005514 transform or axis order is unexpected.');
        }
    }
}
