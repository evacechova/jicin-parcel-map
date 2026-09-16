<?php

declare(strict_types=1);

use App\Import\Download\DownloadAttemptResult;
use App\Import\Download\DownloadManager;
use App\Import\Download\DownloadTransport;
use App\Import\Gml\GmlStreamParser;
use App\Import\ImportException;
use App\Import\Scope\CadastralScope;
use App\Import\Source\GmlZipArchive;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class FakeDownloadTransport implements DownloadTransport
{
    public int $calls = 0;

    /** @param list<DownloadAttemptResult> $results */
    public function __construct(
        private array $results,
        private readonly string $successfulPayload,
    ) {
    }

    public function fetch(string $url, string $destination): DownloadAttemptResult
    {
        $result = $this->results[$this->calls] ?? throw new RuntimeException('Unexpected download attempt.');
        ++$this->calls;
        $payload = $result->httpStatus !== null && $result->httpStatus >= 200 && $result->httpStatus < 300
            ? $this->successfulPayload
            : 'failed-attempt';
        if (file_put_contents($destination, $payload) === false) {
            throw new RuntimeException('Cannot create fake download.');
        }

        return $result->httpStatus !== null
            ? DownloadAttemptResult::http($result->httpStatus, $result->retryAfterSeconds, strlen($payload))
            : DownloadAttemptResult::transportFailure($result->curlError ?? CURLE_RECV_ERROR, strlen($payload));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
    }
}

function expectImportError(string $expectedCode, callable $operation): void
{
    try {
        $operation();
    } catch (ImportException $exception) {
        assertSameValue($expectedCode, $exception->errorCode, 'Unexpected import error code.');
        return;
    }

    throw new RuntimeException(sprintf('Expected ImportException %s.', $expectedCode));
}

function createZip(string $path, string $entry, string $xml): void
{
    $zip = new ZipArchive();
    assertSameValue(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE), 'Cannot create ZIP fixture.');
    assertTrue($zip->addFromString($entry, $xml), 'Cannot add XML fixture to ZIP.');
    assertTrue($zip->close(), 'Cannot close ZIP fixture.');
}

function removeTestDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $item) {
        if ($item->isDir()) {
            removeTestDirectory($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

$projectRoot = dirname(__DIR__, 2);
$fixtureXml = file_get_contents($projectRoot . '/tests/Fixtures/Import/cp-valid.xml');
$malformedXml = file_get_contents($projectRoot . '/tests/Fixtures/Import/cp-malformed.xml');
$unsupportedGeometryXml = file_get_contents($projectRoot . '/tests/Fixtures/Import/cp-unsupported-geometry.xml');
assertTrue(
    is_string($fixtureXml) && is_string($malformedXml) && is_string($unsupportedGeometryXml),
    'Cannot read importer fixtures.',
);

$temporaryDirectory = sys_get_temp_dir() . '/viagem importer fixtures-' . getmypid();
if (!mkdir($temporaryDirectory, 0700) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException('Cannot create importer test directory.');
}

try {
    $scope = CadastralScope::load('jicin', $projectRoot);
    assertSameValue(240, count($scope->territories), 'Jičín scope count differs.');
    assertSameValue('Bašnice', $scope->territories['601101'], 'Scope name differs.');
    assertSameValue(
        'https://services.cuzk.gov.cz/gml/inspire/cp/epsg-5514/601101.zip',
        $scope->downloadUrl('601101'),
        'Deterministic ČÚZK URL differs.',
    );
    expectImportError('ku_outside_scope', static fn () => $scope->downloadUrl('000000'));

    $validZipPath = $temporaryDirectory . '/601101.zip';
    createZip($validZipPath, '601101.xml', $fixtureXml);
    $source = GmlZipArchive::inspect($validZipPath, '601101');
    assertSameValue('601101.xml', $source->entryName, 'ZIP entry selection differs.');

    $unexpectedZipPath = $temporaryDirectory . '/unexpected.zip';
    createZip($unexpectedZipPath, '../601101.xml', $fixtureXml);
    expectImportError('unexpected_zip_contents', static fn () => GmlZipArchive::inspect($unexpectedZipPath, '601101'));

    $parser = new GmlStreamParser();
    $zoning = $parser->readZoning($source);
    assertSameValue('fixture-gml-zoning-id', $zoning->gmlId, 'Zoning gml:id differs.');
    assertSameValue('CZ.601101', $zoning->localId, 'Zoning must use inspireId/base:localId.');
    assertSameValue('601101', $zoning->kuCode, 'Zoning national code differs.');
    assertSameValue(2, $zoning->geometry->polygonCount, 'Zoning MultiSurface polygon count differs.');
    assertSameValue('MULTIPOLYGON(((-10 -10,0 -10,0 0,-10 0,-10 -10)),((10 10,20 10,20 20,10 20,10 10)))', $zoning->geometry->wkt, 'Zoning WKT differs.');
    assertSameValue(null, $zoning->validFrom, 'Nil zoning validFrom must be null.');

    $parcels = iterator_to_array($parser->parcels($source), false);
    assertSameValue(2, count($parcels), 'Parcel fixture count differs.');
    assertSameValue('fixture-gml-parcel-id-1', $parcels[0]->gmlId, 'Parcel gml:id differs.');
    assertSameValue('CP.fixture-1', $parcels[0]->localId, 'Parcel must use inspireId/base:localId.');
    assertSameValue('64.25', $parcels[0]->areaSquareMetres, 'Source areaValue differs.');
    assertSameValue(1, $parcels[0]->geometry->interiorRingCount, 'Interior ring was not preserved.');
    assertSameValue(
        'MULTIPOLYGON(((0 0,10 0,10 10,0 10,0 0),(2 2,2 4,4 4,4 2,2 2)))',
        $parcels[0]->geometry->wkt,
        'Polygon-with-hole WKT differs.',
    );
    assertSameValue(2, $parcels[1]->geometry->polygonCount, 'Parcel MultiSurface polygon count differs.');
    assertSameValue(null, $parcels[1]->referencePointWkt, 'Missing optional reference point must stay null.');
    assertSameValue('2020-01-02T03:04:05Z', $parcels[1]->validFrom, 'Non-null validFrom differs.');

    $unclosedPath = $temporaryDirectory . '/unclosed.zip';
    $unclosedXml = str_replace(
        '0 0 10 0 10 10 0 10 0 0',
        '0 0 10 0 10 10 0 10 1 1',
        $fixtureXml,
    );
    createZip($unclosedPath, '601101.xml', $unclosedXml);
    $unclosedSource = GmlZipArchive::inspect($unclosedPath, '601101');
    expectImportError('unclosed_ring', static fn () => iterator_to_array($parser->parcels($unclosedSource), false));

    $missingAreaPath = $temporaryDirectory . '/missing-area.zip';
    $missingAreaXml = preg_replace('/\s*<cp:areaValue uom="m2">64\.25<\/cp:areaValue>/', '', $fixtureXml, 1);
    assertTrue(is_string($missingAreaXml), 'Cannot build missing-area fixture.');
    createZip($missingAreaPath, '601101.xml', $missingAreaXml);
    $missingAreaSource = GmlZipArchive::inspect($missingAreaPath, '601101');
    expectImportError('missing_required_field', static fn () => iterator_to_array($parser->parcels($missingAreaSource), false));

    $malformedPath = $temporaryDirectory . '/malformed.zip';
    createZip($malformedPath, '601101.xml', $malformedXml);
    $malformedSource = GmlZipArchive::inspect($malformedPath, '601101');
    expectImportError('malformed_gml', static fn () => $parser->readZoning($malformedSource));

    $unsupportedGeometryPath = $temporaryDirectory . '/unsupported-geometry.zip';
    createZip($unsupportedGeometryPath, '601101.xml', $unsupportedGeometryXml);
    $unsupportedGeometrySource = GmlZipArchive::inspect($unsupportedGeometryPath, '601101');
    expectImportError(
        'unsupported_geometry',
        static fn () => iterator_to_array($parser->parcels($unsupportedGeometrySource), false),
    );

    $zipPayload = file_get_contents($validZipPath);
    assertTrue(is_string($zipPayload), 'Cannot read generated ZIP payload.');
    $transport = new FakeDownloadTransport([
        DownloadAttemptResult::http(500, null, 0),
        DownloadAttemptResult::http(429, 2, 0),
        DownloadAttemptResult::http(200, null, strlen($zipPayload)),
    ], $zipPayload);
    $sleeps = [];
    $manager = new DownloadManager($transport, static function (int $seconds) use (&$sleeps): void {
        $sleeps[] = $seconds;
    });
    $downloadTarget = $temporaryDirectory . '/downloaded/601101.zip';
    mkdir(dirname($downloadTarget), 0700);
    $downloaded = $manager->download(
        $scope->downloadUrl('601101'),
        $downloadTarget,
        static fn (string $path) => GmlZipArchive::inspect($path, '601101'),
    );
    assertSameValue(3, $downloaded->attemptCount, 'Retry attempt count differs.');
    assertSameValue([1, 2], $sleeps, 'Retry delays/Retry-After handling differ.');
    assertTrue(file_exists($downloadTarget), 'Verified download was not atomically published.');
    assertSameValue([], glob($downloadTarget . '.attempt-*.part') ?: [], 'Partial files remain after retry.');

    $notFoundTransport = new FakeDownloadTransport([DownloadAttemptResult::http(404, null, 0)], $zipPayload);
    $notFoundManager = new DownloadManager($notFoundTransport, static function (): void {});
    expectImportError('download_http_error', static fn () => $notFoundManager->download(
        $scope->downloadUrl('601101'),
        $temporaryDirectory . '/downloaded/404.zip',
        static fn (string $path) => GmlZipArchive::inspect($path, '601101'),
    ));
    assertSameValue(1, $notFoundTransport->calls, 'HTTP 404 must not be retried.');

    $networkTransport = new FakeDownloadTransport([
        DownloadAttemptResult::transportFailure(CURLE_COULDNT_RESOLVE_HOST, 0),
        DownloadAttemptResult::http(200, null, strlen($zipPayload)),
    ], $zipPayload);
    $networkSleeps = [];
    $networkManager = new DownloadManager($networkTransport, static function (int $seconds) use (&$networkSleeps): void {
        $networkSleeps[] = $seconds;
    });
    $networkDownload = $networkManager->download(
        $scope->downloadUrl('601101'),
        $temporaryDirectory . '/downloaded/network-retry.zip',
        static fn (string $path) => GmlZipArchive::inspect($path, '601101'),
    );
    assertSameValue(2, $networkDownload->attemptCount, 'Retryable transport error was not retried.');
    assertSameValue([1], $networkSleeps, 'Transport retry delay differs.');

    $corruptTransport = new FakeDownloadTransport([DownloadAttemptResult::http(200, null, 9)], 'not-a-zip');
    $corruptManager = new DownloadManager($corruptTransport, static function (): void {});
    expectImportError('invalid_zip', static fn () => $corruptManager->download(
        $scope->downloadUrl('601101'),
        $temporaryDirectory . '/downloaded/corrupt.zip',
        static fn (string $path) => GmlZipArchive::inspect($path, '601101'),
    ));
    assertSameValue(1, $corruptTransport->calls, 'Corrupt ZIP must not be retried.');
    assertSameValue([], glob($temporaryDirectory . '/downloaded/corrupt.zip.attempt-*.part') ?: [], 'Corrupt ZIP partial remains.');

    echo "Importer parser/download verification passed.\n";
} finally {
    removeTestDirectory($temporaryDirectory);
}
