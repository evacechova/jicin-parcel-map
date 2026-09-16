<?php

declare(strict_types=1);

namespace App\Import\Database;

use App\Import\ImportException;
use App\Import\Scope\CadastralScope;
use Closure;
use FilesystemIterator;

final class FullImportRunner
{
    /** @param null|Closure(string): void $progress */
    public function __construct(
        private readonly ImportRunRepository $runs,
        private readonly TerritoryImporter $territoryImporter,
        private readonly DatasetPublicationService $publication,
        private readonly string $projectRoot,
        private readonly ?Closure $progress = null,
    ) {
    }

    public function run(CadastralScope $scope, bool $keepArtifacts = false): FullImportResult
    {
        $startedAt = microtime(true);
        $runId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $storageRoot = $this->projectRoot . '/storage/imports';
        $runDirectory = $storageRoot . '/' . $runId;
        $downloadDirectory = $runDirectory . '/downloads';
        if (!mkdir($downloadDirectory, 0700, true) && !is_dir($downloadDirectory)) {
            throw new ImportException('run_storage_error', 'Cannot create the run-specific import directory.');
        }

        $datasetId = null;
        $totalParcels = 0;
        $totalBytes = 0;
        try {
            $datasetId = $this->runs->create($scope);
            $this->writeProgress(sprintf(
                'Run %s: dataset %d, scope %s, %d KÚ.',
                $runId,
                $datasetId,
                $scope->code,
                count($scope->territoryCodes()),
            ));

            $codes = $scope->territoryCodes();
            foreach ($codes as $index => $kuCode) {
                $result = $this->territoryImporter->importTerritory(
                    $datasetId,
                    $scope,
                    $kuCode,
                    $downloadDirectory . '/' . $kuCode . '.zip',
                );
                $totalParcels += $result->parcelCount;
                $totalBytes += $result->sourceSizeBytes;
                $this->writeProgress(sprintf(
                    '[%d/%d] KÚ %s imported: %d parcels, %d bytes.',
                    $index + 1,
                    count($codes),
                    $kuCode,
                    $result->parcelCount,
                    $result->sourceSizeBytes,
                ));
            }

            $this->writeProgress('All KÚ imported; validating complete dataset.');
            $activation = $this->publication->validateAndActivate(
                $datasetId,
                $scope,
                $totalParcels,
                $totalBytes,
            );

            $artifactsRetained = $keepArtifacts;
            if (!$keepArtifacts) {
                try {
                    $this->removeRunDirectory($runDirectory, $storageRoot);
                } catch (ImportException $exception) {
                    $artifactsRetained = true;
                    $this->writeProgress(sprintf(
                        'Dataset activated, but successful-run artifacts could not be removed: %s',
                        $exception->getMessage(),
                    ));
                }
            }

            return new FullImportResult(
                $runId,
                $runDirectory,
                $datasetId,
                $activation->validation->territoryCount,
                $activation->validation->parcelCount,
                $activation->validation->sourceSizeBytes,
                microtime(true) - $startedAt,
                $artifactsRetained,
                $activation->previousDatasetId,
            );
        } catch (\Throwable $exception) {
            if ($datasetId !== null) {
                $errorCode = $exception instanceof ImportException ? $exception->errorCode : 'full_import_error';
                $errorMessage = $exception instanceof ImportException ? $exception->getMessage() : 'Full cadastral import failed.';
                $this->runs->failDatasetIfImporting($datasetId, $errorCode, $errorMessage);
                $this->writeProgress(sprintf(
                    'Run failed; dataset %d was not activated. Artifacts retained in %s.',
                    $datasetId,
                    $runDirectory,
                ));
            }
            throw $exception;
        }
    }

    private function writeProgress(string $message): void
    {
        if ($this->progress !== null) {
            ($this->progress)($message);
        }
    }

    private function removeRunDirectory(string $runDirectory, string $storageRoot): void
    {
        $realRun = realpath($runDirectory);
        $realRoot = realpath($storageRoot);
        if ($realRun === false || $realRoot === false || !str_starts_with($realRun, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new ImportException('run_storage_error', 'Refusing to remove an unexpected import directory.');
        }

        $this->removeDirectoryContents($realRun);
        if (!rmdir($realRun)) {
            throw new ImportException('run_storage_error', 'Cannot remove the successful run directory.');
        }
    }

    private function removeDirectoryContents(string $directory): void
    {
        foreach (new FilesystemIterator($directory) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectoryContents($item->getPathname());
                if (!rmdir($item->getPathname())) {
                    throw new ImportException('run_storage_error', 'Cannot remove a successful run subdirectory.');
                }
            } elseif (!unlink($item->getPathname())) {
                throw new ImportException('run_storage_error', 'Cannot remove a successful run artifact.');
            }
        }
    }
}
