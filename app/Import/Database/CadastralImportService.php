<?php

declare(strict_types=1);

namespace App\Import\Database;

use App\Import\Download\DownloadAttemptResult;
use App\Import\Download\DownloadManager;
use App\Import\Gml\GmlStreamParser;
use App\Import\ImportException;
use App\Import\Scope\CadastralScope;
use App\Import\Source\GmlZipArchive;
use PDO;
use PDOException;

final class CadastralImportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ImportRunRepository $runs,
        private readonly CadastralWriteRepository $writes,
        private readonly DownloadManager $downloads,
        private readonly GmlStreamParser $parser,
    ) {
    }

    public function importTerritory(
        int $datasetId,
        CadastralScope $scope,
        string $kuCode,
        string $downloadTarget,
    ): ImportTerritoryResult {
        $sourceUrl = $scope->downloadUrl($kuCode);
        $expectedName = $scope->territories[$kuCode];
        $this->runs->markProcessing($datasetId, $kuCode);

        try {
            $downloaded = $this->downloads->download(
                $sourceUrl,
                $downloadTarget,
                static fn (string $path) => GmlZipArchive::inspect($path, $kuCode),
                fn (int $attempt, DownloadAttemptResult $result) => $this->runs->recordDownloadAttempt(
                    $datasetId,
                    $kuCode,
                    $attempt,
                    $result,
                ),
            );
            $this->runs->recordDownloadedArtifact($datasetId, $kuCode, $downloaded);
            $source = GmlZipArchive::inspect($downloaded->path, $kuCode);

            $this->pdo->beginTransaction();
            try {
                $this->writes->lockImportableCheckpoint($datasetId, $kuCode);
                $zoning = $this->parser->readZoning($source);
                if ($zoning->kuCode !== $kuCode || $zoning->label !== $expectedName) {
                    throw new ImportException('zoning_scope_mismatch', 'Parsed CadastralZoning does not match the configured KÚ.');
                }

                $territoryId = $this->writes->insertTerritory($datasetId, $zoning);
                $parcelCount = 0;
                foreach ($this->parser->parcels($source) as $parcel) {
                    if ($parcel->zoningKuCode !== $kuCode) {
                        throw new ImportException('parcel_zoning_mismatch', 'Parsed parcel references a different KÚ.');
                    }
                    $this->writes->insertParcel($datasetId, $territoryId, $parcel);
                    ++$parcelCount;
                }

                $this->writes->completeTerritory($datasetId, $kuCode, $parcelCount);
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }

            return new ImportTerritoryResult(
                $datasetId,
                $territoryId,
                $kuCode,
                $parcelCount,
                $downloaded->sha256,
                $downloaded->sizeBytes,
            );
        } catch (\Throwable $exception) {
            $failure = self::safeFailure($exception);
            try {
                $this->runs->markFailed($datasetId, $kuCode, $failure->errorCode, $failure->getMessage());
            } catch (\Throwable $trackingException) {
                throw new ImportException(
                    'failure_recording_error',
                    'Import failed and its failure state could not be persisted.',
                    $trackingException,
                );
            }
            throw $failure;
        }
    }

    private static function safeFailure(\Throwable $exception): ImportException
    {
        if ($exception instanceof ImportException) {
            return $exception;
        }
        if ($exception instanceof PDOException) {
            return new ImportException('database_error', 'Database operation failed.', $exception);
        }

        return new ImportException('import_error', 'Cadastral import failed.', $exception);
    }
}
