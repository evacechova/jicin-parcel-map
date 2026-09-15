CREATE TABLE IF NOT EXISTS dataset (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider VARCHAR(64) NOT NULL,
    scope_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    native_srid INT UNSIGNED NOT NULL,
    display_srid INT UNSIGNED NOT NULL,
    source_url VARCHAR(512) NOT NULL,
    status ENUM('importing', 'ready', 'failed', 'retired') NOT NULL,
    territory_count INT UNSIGNED NOT NULL DEFAULT 0,
    parcel_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    validation_report JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- migrate:statement
CREATE TABLE IF NOT EXISTS active_dataset (
    slot TINYINT UNSIGNED NOT NULL,
    dataset_id BIGINT UNSIGNED NOT NULL,
    activated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (slot),
    UNIQUE KEY uq_active_dataset_dataset (dataset_id),
    CONSTRAINT chk_active_dataset_singleton CHECK (slot = 1),
    CONSTRAINT fk_active_dataset_dataset
        FOREIGN KEY (dataset_id) REFERENCES dataset (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- migrate:statement
CREATE TABLE IF NOT EXISTS import_territory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dataset_id BIGINT UNSIGNED NOT NULL,
    ku_code CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_url VARCHAR(512) NOT NULL,
    source_revision VARCHAR(255) NULL,
    source_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_size_bytes BIGINT UNSIGNED NULL,
    retrieved_at DATETIME(6) NULL,
    status ENUM('pending', 'processing', 'imported', 'failed') NOT NULL DEFAULT 'pending',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME(6) NULL,
    last_http_status SMALLINT UNSIGNED NULL,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    error_message VARCHAR(255) NULL,
    parcel_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_import_territory_dataset_ku (dataset_id, ku_code),
    CONSTRAINT fk_import_territory_dataset
        FOREIGN KEY (dataset_id) REFERENCES dataset (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
