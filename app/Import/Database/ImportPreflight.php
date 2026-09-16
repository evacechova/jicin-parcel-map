<?php

declare(strict_types=1);

namespace App\Import\Database;

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
            WHERE SRS_ID IN (5514, 4326)
            SQL)->fetchColumn();
        if ($srids !== 2) {
            throw new ImportException('spatial_preflight_failed', 'Required SRIDs 5514 and 4326 are not registered.');
        }

        $point = $this->pdo->query(<<<'SQL'
            SELECT ST_Longitude(transformed) AS longitude,
                   ST_Latitude(transformed) AS latitude
            FROM (
                SELECT ST_Transform(
                    ST_GeomFromText(
                        'POINT(-671984.1403374915 -1013081.1797817094)',
                        5514,
                        'axis-order=srid-defined'
                    ),
                    4326
                ) AS transformed
            ) AS control_point
            SQL)->fetch();
        if (
            $point === false
            || abs((float) $point['longitude'] - 15.3516) > 0.00001
            || abs((float) $point['latitude'] - 50.4372) > 0.00001
        ) {
            throw new ImportException('spatial_preflight_failed', 'EPSG:5514 to EPSG:4326 transform or axis order is unexpected.');
        }
    }
}
