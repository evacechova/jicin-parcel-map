<?php

declare(strict_types=1);

use App\Import\Download\CurlDownloadTransport;
use App\Import\Download\DownloadManager;
use App\Import\Gml\GmlStreamParser;
use App\Import\Scope\CadastralScope;
use App\Import\Source\GmlZipArchive;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function smokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$projectRoot = dirname(__DIR__, 2);
$scope = CadastralScope::load('jicin', $projectRoot);
$kuCode = '601101';
$runDirectory = sys_get_temp_dir() . '/viagem-cuzk-smoke-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
$downloadDirectory = $runDirectory . '/downloads';
if (!mkdir($downloadDirectory, 0700, true) && !is_dir($downloadDirectory)) {
    throw new RuntimeException('Cannot create smoke-test download directory.');
}

$zipPath = $downloadDirectory . '/' . $kuCode . '.zip';
$completed = false;

try {
    $manager = new DownloadManager(new CurlDownloadTransport());
    $downloaded = $manager->download(
        $scope->downloadUrl($kuCode),
        $zipPath,
        static fn (string $path) => GmlZipArchive::inspect($path, $kuCode),
    );

    $source = GmlZipArchive::inspect($downloaded->path, $kuCode);
    $parser = new GmlStreamParser();
    $memoryBeforeParsing = memory_get_usage(true);
    $zoning = $parser->readZoning($source);
    smokeAssert($zoning->kuCode === $kuCode, 'Real zoning KÚ code differs from the requested source.');
    smokeAssert($zoning->localId === 'CZ.' . $kuCode, 'Real zoning localId differs from the source KÚ identity.');
    smokeAssert($zoning->identifierNamespace === 'CZ-00025712-CUZK_CP', 'Real zoning INSPIRE namespace differs.');

    $parcelCount = 0;
    $holeCount = 0;
    $multiSurfaceParcelCount = 0;
    $localIdEqualsGmlIdCount = 0;
    $maximumCoordinates = 0;
    $firstParcel = null;
    foreach ($parser->parcels($source) as $parcel) {
        ++$parcelCount;
        $firstParcel ??= [
            'gml_id' => $parcel->gmlId,
            'local_id' => $parcel->localId,
            'label' => $parcel->label,
            'area_m2' => $parcel->areaSquareMetres,
            'geometry_type' => str_starts_with($parcel->geometry->wkt, 'MULTIPOLYGON(') ? 'MULTIPOLYGON' : 'unexpected',
        ];
        smokeAssert($parcel->zoningKuCode === $kuCode, 'A real parcel references a different KÚ.');
        smokeAssert($parcel->identifierNamespace === 'CZ-00025712-CUZK_CP', 'A real parcel INSPIRE namespace differs.');
        $holeCount += $parcel->geometry->interiorRingCount;
        $multiSurfaceParcelCount += $parcel->geometry->polygonCount > 1 ? 1 : 0;
        $localIdEqualsGmlIdCount += $parcel->localId === $parcel->gmlId ? 1 : 0;
        $maximumCoordinates = max($maximumCoordinates, $parcel->geometry->coordinateCount);
        unset($parcel);
    }
    $peakMemory = memory_get_peak_usage(true);
    $parsingPeakDelta = max(0, $peakMemory - $memoryBeforeParsing);

    smokeAssert($parcelCount > 0, 'Real GML contains no cadastral parcels.');
    smokeAssert($source->uncompressedBytes > $parsingPeakDelta, 'Parser peak-memory delta suggests document-wide buffering.');

    echo json_encode([
        'url' => $scope->downloadUrl($kuCode),
        'zip_bytes' => $downloaded->sizeBytes,
        'zip_sha256' => $downloaded->sha256,
        'download_attempts' => $downloaded->attemptCount,
        'entry' => $source->entryName,
        'xml_bytes' => $source->uncompressedBytes,
        'zoning' => [
            'gml_id' => $zoning->gmlId,
            'local_id' => $zoning->localId,
            'name' => $zoning->label,
            'polygon_count' => $zoning->geometry->polygonCount,
            'interior_ring_count' => $zoning->geometry->interiorRingCount,
        ],
        'parcels' => [
            'count' => $parcelCount,
            'first' => $firstParcel,
            'interior_ring_count' => $holeCount,
            'multi_surface_count' => $multiSurfaceParcelCount,
            'local_id_equals_gml_id_count' => $localIdEqualsGmlIdCount,
            'maximum_coordinate_count' => $maximumCoordinates,
        ],
        'memory' => [
            'before_parsing_bytes' => $memoryBeforeParsing,
            'peak_bytes' => $peakMemory,
            'parsing_peak_delta_bytes' => $parsingPeakDelta,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    $completed = true;
} finally {
    if ($completed) {
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
        if (is_dir($downloadDirectory)) {
            rmdir($downloadDirectory);
        }
        if (is_dir($runDirectory)) {
            rmdir($runDirectory);
        }
    } else {
        fwrite(STDERR, sprintf("Smoke-test artifacts retained at %s\n", $runDirectory));
    }
}
