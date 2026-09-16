<?php

declare(strict_types=1);

namespace App\Import\Database;

use App\Import\ImportException;
use App\Import\Scope\CadastralScope;
use Closure;
use PDO;

final class DatasetPublicationService
{
    /** @param null|Closure(): void $beforePointerWrite Deterministic transaction fault hook for integration tests. */
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatasetValidator $validator,
        private readonly ?Closure $beforePointerWrite = null,
    ) {
    }

    public function validateAndActivate(
        int $datasetId,
        CadastralScope $scope,
        ?int $expectedParcelCount = null,
        ?int $expectedSourceSizeBytes = null,
    ): ActivationResult {
        $initialValidation = $this->validator->validate($datasetId, $scope);
        if (
            ($expectedParcelCount !== null && $initialValidation->parcelCount !== $expectedParcelCount)
            || ($expectedSourceSizeBytes !== null && $initialValidation->sourceSizeBytes !== $expectedSourceSizeBytes)
        ) {
            throw new ImportException('full_run_count_mismatch', 'Full-run totals differ from the validated dataset.');
        }

        if ($this->pdo->inTransaction()) {
            throw new ImportException('transaction_state_error', 'Cannot activate a dataset inside another transaction.');
        }

        $this->pdo->beginTransaction();
        try {
            $pointer = $this->pdo->query(
                'SELECT dataset_id FROM active_dataset WHERE slot = 1 FOR UPDATE',
            )->fetch();
            $previousDatasetId = $pointer === false ? null : (int) $pointer['dataset_id'];

            $candidate = $this->pdo->prepare('SELECT status FROM dataset WHERE id = :dataset_id FOR UPDATE');
            $candidate->execute(['dataset_id' => $datasetId]);
            $candidateStatus = $candidate->fetchColumn();
            if ($candidateStatus !== 'importing') {
                throw new ImportException('dataset_not_activatable', 'Candidate dataset is not importing.');
            }
            if ($previousDatasetId === $datasetId) {
                throw new ImportException('dataset_not_activatable', 'Candidate dataset is already referenced as active.');
            }

            if ($previousDatasetId !== null) {
                $previous = $this->pdo->prepare('SELECT status FROM dataset WHERE id = :dataset_id FOR UPDATE');
                $previous->execute(['dataset_id' => $previousDatasetId]);
                if ($previous->fetchColumn() !== 'ready') {
                    throw new ImportException('active_dataset_invalid', 'Current active dataset is not ready.');
                }
            }

            // Full spatial/source validation has just passed. Under the locks,
            // cheaply prove that its scope and all persisted totals are unchanged.
            $this->validator->revalidateActivationState($datasetId, $scope, $initialValidation);
            $encodedReport = json_encode($initialValidation->report, JSON_THROW_ON_ERROR);

            $activate = $this->pdo->prepare(<<<'SQL'
                UPDATE dataset
                SET status = 'ready',
                    validation_report = :validation_report,
                    completed_at = CURRENT_TIMESTAMP(6)
                WHERE id = :dataset_id AND status = 'importing'
                SQL);
            $activate->execute(['validation_report' => $encodedReport, 'dataset_id' => $datasetId]);
            if ($activate->rowCount() !== 1) {
                throw new ImportException('activation_state_error', 'Candidate dataset could not become ready.');
            }

            if ($previousDatasetId === null) {
                if ($this->beforePointerWrite !== null) {
                    ($this->beforePointerWrite)();
                }
                $pointerWrite = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO active_dataset (slot, dataset_id, activated_at)
                    VALUES (1, :dataset_id, CURRENT_TIMESTAMP(6))
                    SQL);
                $pointerWrite->execute(['dataset_id' => $datasetId]);
            } else {
                $retire = $this->pdo->prepare(<<<'SQL'
                    UPDATE dataset
                    SET status = 'retired'
                    WHERE id = :dataset_id AND status = 'ready'
                    SQL);
                $retire->execute(['dataset_id' => $previousDatasetId]);
                if ($retire->rowCount() !== 1) {
                    throw new ImportException('activation_state_error', 'Previous active dataset could not be retired.');
                }

                if ($this->beforePointerWrite !== null) {
                    ($this->beforePointerWrite)();
                }
                $pointerWrite = $this->pdo->prepare(<<<'SQL'
                    UPDATE active_dataset
                    SET dataset_id = :dataset_id, activated_at = CURRENT_TIMESTAMP(6)
                    WHERE slot = 1 AND dataset_id = :previous_dataset_id
                    SQL);
                $pointerWrite->execute([
                    'dataset_id' => $datasetId,
                    'previous_dataset_id' => $previousDatasetId,
                ]);
                if ($pointerWrite->rowCount() !== 1) {
                    throw new ImportException('activation_state_error', 'Active dataset pointer could not be switched.');
                }
            }

            $this->pdo->commit();

            return new ActivationResult($datasetId, $previousDatasetId, $initialValidation);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
