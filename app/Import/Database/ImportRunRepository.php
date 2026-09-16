<?php

declare(strict_types=1);

namespace App\Import\Database;

use App\Import\Download\DownloadAttemptResult;
use App\Import\Download\DownloadedFile;
use App\Import\ImportException;
use App\Import\Scope\CadastralScope;
use PDO;

final class ImportRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(CadastralScope $scope): int
    {
        if ($this->pdo->inTransaction()) {
            throw new ImportException('transaction_state_error', 'Cannot create an import dataset inside another transaction.');
        }

        $this->pdo->beginTransaction();
        try {
            $dataset = $this->pdo->prepare(<<<'SQL'
                INSERT INTO dataset (
                    country_code, provider, scope_code, native_srid, display_srid,
                    source_url, status
                ) VALUES ('CZ', 'CUZK', :scope_code, 5514, 4326, :source_url, 'importing')
                SQL);
            $dataset->execute([
                'scope_code' => $scope->code,
                'source_url' => $scope->sourceBaseUrl,
            ]);
            $datasetId = (int) $this->pdo->lastInsertId();

            $checkpoint = $this->pdo->prepare(<<<'SQL'
                INSERT INTO import_territory (dataset_id, ku_code, source_url, status)
                VALUES (:dataset_id, :ku_code, :source_url, 'pending')
                SQL);
            foreach ($scope->territoryCodes() as $kuCode) {
                $checkpoint->execute([
                    'dataset_id' => $datasetId,
                    'ku_code' => $kuCode,
                    'source_url' => $scope->downloadUrl($kuCode),
                ]);
            }

            $this->pdo->commit();

            return $datasetId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markProcessing(int $datasetId, string $kuCode): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE import_territory AS checkpoint
            INNER JOIN dataset AS import_dataset ON import_dataset.id = checkpoint.dataset_id
            SET checkpoint.status = 'processing',
                checkpoint.error_code = NULL,
                checkpoint.error_message = NULL
            WHERE checkpoint.dataset_id = :dataset_id
              AND checkpoint.ku_code = :ku_code
              AND checkpoint.status = 'pending'
              AND import_dataset.status = 'importing'
            SQL);
        $statement->execute(['dataset_id' => $datasetId, 'ku_code' => $kuCode]);
        if ($statement->rowCount() !== 1) {
            throw new ImportException('checkpoint_not_pending', 'Import checkpoint is not pending in an importing dataset.');
        }
    }

    public function recordDownloadAttempt(
        int $datasetId,
        string $kuCode,
        int $attempt,
        DownloadAttemptResult $result,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE import_territory
            SET attempt_count = :attempt_count,
                last_attempt_at = CURRENT_TIMESTAMP(6),
                last_http_status = :http_status
            WHERE dataset_id = :dataset_id
              AND ku_code = :ku_code
              AND status = 'processing'
            SQL);
        $statement->bindValue('attempt_count', $attempt, PDO::PARAM_INT);
        $statement->bindValue('http_status', $result->httpStatus, $result->httpStatus === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('dataset_id', $datasetId, PDO::PARAM_INT);
        $statement->bindValue('ku_code', $kuCode);
        $statement->execute();
        $this->assertCheckpointExists($datasetId, $kuCode, 'processing');
    }

    public function recordDownloadedArtifact(int $datasetId, string $kuCode, DownloadedFile $file): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE import_territory
            SET source_checksum = :checksum,
                source_size_bytes = :size_bytes,
                retrieved_at = CURRENT_TIMESTAMP(6),
                last_http_status = :http_status
            WHERE dataset_id = :dataset_id
              AND ku_code = :ku_code
              AND status = 'processing'
            SQL);
        $statement->execute([
            'checksum' => $file->sha256,
            'size_bytes' => $file->sizeBytes,
            'http_status' => $file->httpStatus,
            'dataset_id' => $datasetId,
            'ku_code' => $kuCode,
        ]);
        $this->assertCheckpointExists($datasetId, $kuCode, 'processing');
    }

    public function markFailed(int $datasetId, string $kuCode, string $errorCode, string $errorMessage): void
    {
        if ($this->pdo->inTransaction()) {
            throw new ImportException('transaction_state_error', 'Cannot persist import failure inside a domain transaction.');
        }

        $safeCode = substr($errorCode, 0, 64);
        $safeMessage = mb_substr($errorMessage, 0, 255);
        $this->pdo->beginTransaction();
        try {
            $checkpoint = $this->pdo->prepare(<<<'SQL'
                UPDATE import_territory
                SET status = 'failed', error_code = :error_code, error_message = :error_message
                WHERE dataset_id = :dataset_id
                  AND ku_code = :ku_code
                  AND status IN ('pending', 'processing')
                SQL);
            $checkpoint->execute([
                'error_code' => $safeCode,
                'error_message' => $safeMessage,
                'dataset_id' => $datasetId,
                'ku_code' => $kuCode,
            ]);

            $dataset = $this->pdo->prepare(<<<'SQL'
                UPDATE dataset
                SET status = 'failed', completed_at = CURRENT_TIMESTAMP(6)
                WHERE id = :dataset_id AND status = 'importing'
                SQL);
            $dataset->execute(['dataset_id' => $datasetId]);

            if ($checkpoint->rowCount() !== 1 || $dataset->rowCount() !== 1) {
                throw new ImportException('failure_state_error', 'Cannot persist the failed import state.');
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param null|array<string, mixed> $validationReport */
    public function failDatasetIfImporting(
        int $datasetId,
        string $errorCode,
        string $errorMessage,
        ?array $validationReport = null,
    ): void {
        if ($this->pdo->inTransaction()) {
            throw new ImportException('transaction_state_error', 'Cannot persist dataset failure inside another transaction.');
        }

        $report = $validationReport ?? [
            'valid' => false,
            'error_code' => substr($errorCode, 0, 64),
            'error_message' => mb_substr($errorMessage, 0, 255),
            'validated_at' => gmdate('c'),
        ];
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE dataset
            SET status = 'failed',
                validation_report = :validation_report,
                completed_at = CURRENT_TIMESTAMP(6)
            WHERE id = :dataset_id AND status = 'importing'
            SQL);
        $statement->execute([
            'validation_report' => json_encode($report, JSON_THROW_ON_ERROR),
            'dataset_id' => $datasetId,
        ]);
    }

    private function assertCheckpointExists(int $datasetId, string $kuCode, string $status): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM import_territory
            WHERE dataset_id = :dataset_id AND ku_code = :ku_code AND status = :status
            SQL);
        $statement->execute([
            'dataset_id' => $datasetId,
            'ku_code' => $kuCode,
            'status' => $status,
        ]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new ImportException('checkpoint_state_error', 'Import checkpoint has an unexpected state.');
        }
    }
}
