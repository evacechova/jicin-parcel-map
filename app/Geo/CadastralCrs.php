<?php

declare(strict_types=1);

namespace App\Geo;

use PDO;
use RuntimeException;

final class CadastralCrs
{
    public const int STORAGE_SRID = 5514;
    public const int TRANSFORM_SRID = 1005514;
    public const int PUBLIC_SRID = 4326;

    public const string NAME = 'Viagem S-JTSK Krovak East North EPSG 5239';
    public const string DESCRIPTION = 'Application-owned S-JTSK definition using the position-vector WKT1 equivalent of EPSG coordinate-frame operation 5239; not a survey-grade grid transformation.';
    public const string DEFINITION = 'PROJCS["Viagem S-JTSK / Krovak East North via EPSG 5239",GEOGCS["S-JTSK",DATUM["System of the Unified Trigonometrical Cadastral Network",SPHEROID["Bessel 1841",6377397.155,299.1528128,AUTHORITY["EPSG","7004"]],TOWGS84[572.213,85.334,461.94,4.9732,1.529,5.2484,3.5378]],PRIMEM["Greenwich",0,AUTHORITY["EPSG","8901"]],UNIT["degree",0.017453292519943278,AUTHORITY["EPSG","9122"]],AXIS["Lat",NORTH],AXIS["Lon",EAST]],PROJECTION["Krovak (North Orientated)",AUTHORITY["EPSG","1041"]],PARAMETER["Latitude of projection centre",49.5,AUTHORITY["EPSG","8811"]],PARAMETER["Longitude of origin",24.8333333333333,AUTHORITY["EPSG","8833"]],PARAMETER["Co-latitude of cone axis",30.2881397527778,AUTHORITY["EPSG","1036"]],PARAMETER["Latitude of pseudo standard parallel",78.5,AUTHORITY["EPSG","8818"]],PARAMETER["Scale factor on pseudo standard parallel",0.9999,AUTHORITY["EPSG","8819"]],PARAMETER["False easting",0,AUTHORITY["EPSG","8806"]],PARAMETER["False northing",0,AUTHORITY["EPSG","8807"]],UNIT["metre",1,AUTHORITY["EPSG","9001"]],AXIS["X",EAST],AXIS["Y",NORTH]]';

    public static function definitionChecksum(): string
    {
        return hash('sha256', self::DEFINITION);
    }

    public static function verify(PDO $pdo): void
    {
        $statement = $pdo->prepare(<<<'SQL'
            SELECT SRS_NAME, DEFINITION, DESCRIPTION
            FROM INFORMATION_SCHEMA.ST_SPATIAL_REFERENCE_SYSTEMS
            WHERE SRS_ID = :srs_id
            SQL);
        $statement->execute(['srs_id' => self::TRANSFORM_SRID]);
        $row = $statement->fetch();

        if ($row === false) {
            throw new RuntimeException(sprintf(
                'Required application SRS %d is not registered. Provision database/spatial-reference/1005514.sql as a MySQL administrator.',
                self::TRANSFORM_SRID,
            ));
        }

        $actualDefinition = (string) $row['DEFINITION'];
        if (
            !hash_equals(self::NAME, (string) $row['SRS_NAME'])
            || !hash_equals(self::DESCRIPTION, (string) $row['DESCRIPTION'])
            || !hash_equals(self::definitionChecksum(), hash('sha256', $actualDefinition))
        ) {
            throw new RuntimeException(sprintf(
                'Application SRS %d definition mismatch: expected SHA-256 %s, got %s. Refusing to use an unknown transformation.',
                self::TRANSFORM_SRID,
                self::definitionChecksum(),
                hash('sha256', $actualDefinition),
            ));
        }
    }
}
