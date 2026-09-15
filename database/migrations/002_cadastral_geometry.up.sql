CREATE TABLE IF NOT EXISTS cadastral_territory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dataset_id BIGINT UNSIGNED NOT NULL,
    ku_code CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(255) NOT NULL,
    inspire_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_valid_from DATETIME(6) NULL,
    source_begin_lifespan_version DATETIME(6) NULL,
    geom_native MULTIPOLYGON NOT NULL SRID 5514,
    reference_point_native POINT SRID 5514 NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cadastral_territory_dataset_ku (dataset_id, ku_code),
    UNIQUE KEY uq_cadastral_territory_dataset_inspire (dataset_id, inspire_id),
    UNIQUE KEY uq_cadastral_territory_dataset_id (dataset_id, id),
    SPATIAL INDEX sp_cadastral_territory_geom_native (geom_native),
    CONSTRAINT fk_cadastral_territory_dataset
        FOREIGN KEY (dataset_id) REFERENCES dataset (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- migrate:statement
CREATE TABLE IF NOT EXISTS parcel (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dataset_id BIGINT UNSIGNED NOT NULL,
    territory_id BIGINT UNSIGNED NOT NULL,
    inspire_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(128) NOT NULL,
    national_cadastral_reference VARCHAR(128) NOT NULL,
    area_m2 DECIMAL(16, 2) NOT NULL,
    geom_native MULTIPOLYGON NOT NULL SRID 5514,
    reference_point_native POINT SRID 5514 NULL,
    source_valid_from DATETIME(6) NULL,
    source_begin_lifespan_version DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parcel_dataset_inspire (dataset_id, inspire_id),
    KEY idx_parcel_dataset_territory (dataset_id, territory_id),
    SPATIAL INDEX sp_parcel_geom_native (geom_native),
    CONSTRAINT fk_parcel_territory_dataset
        FOREIGN KEY (dataset_id, territory_id)
        REFERENCES cadastral_territory (dataset_id, id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
