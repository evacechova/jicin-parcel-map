<?php

declare(strict_types=1);

namespace App\Import\Source;

use App\Import\ImportException;
use ZipArchive;

final readonly class GmlZipArchive
{
    private const MAX_UNCOMPRESSED_BYTES = 512 * 1024 * 1024;
    private const MAX_COMPRESSION_RATIO = 1_000;

    private function __construct(
        public string $path,
        public string $entryName,
        public int $uncompressedBytes,
    ) {
    }

    public static function inspect(string $path, string $expectedKuCode): self
    {
        if (preg_match('/^[0-9]{6}$/D', $expectedKuCode) !== 1) {
            throw new ImportException('invalid_ku_code', 'Expected KÚ code must contain six digits.');
        }

        $zip = new ZipArchive();
        $result = $zip->open($path, ZipArchive::CHECKCONS);
        if ($result !== true) {
            throw new ImportException('invalid_zip', sprintf('Cannot open a consistent ZIP archive (code %s).', (string) $result));
        }

        try {
            if ($zip->numFiles !== 1) {
                throw new ImportException('unexpected_zip_contents', 'ČÚZK ZIP must contain exactly one GML/XML entry.');
            }

            $stat = $zip->statIndex(0, ZipArchive::FL_UNCHANGED);
            if ($stat === false) {
                throw new ImportException('invalid_zip', 'Cannot inspect the ČÚZK ZIP entry.');
            }

            $name = (string) ($stat['name'] ?? '');
            if ($name !== $expectedKuCode . '.xml' || basename($name) !== $name) {
                throw new ImportException('unexpected_zip_contents', 'ČÚZK ZIP entry does not match the expected KÚ XML filename.');
            }

            $size = (int) ($stat['size'] ?? -1);
            $compressedSize = (int) ($stat['comp_size'] ?? -1);
            if ($size <= 0 || $size > self::MAX_UNCOMPRESSED_BYTES || $compressedSize <= 0) {
                throw new ImportException('zip_size_limit', 'ČÚZK ZIP entry has an invalid or unsafe size.');
            }

            if (($size / $compressedSize) > self::MAX_COMPRESSION_RATIO) {
                throw new ImportException('zip_ratio_limit', 'ČÚZK ZIP entry exceeds the safe compression ratio.');
            }

            if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                throw new ImportException('encrypted_zip', 'Encrypted ČÚZK ZIP entries are not supported.');
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new ImportException('invalid_zip', 'Cannot stream the ČÚZK ZIP entry.');
            }

            $bytesRead = 0;
            $crc = hash_init('crc32b');
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 64 * 1024);
                    if ($chunk === false) {
                        throw new ImportException('invalid_zip', 'Cannot read the ČÚZK ZIP entry.');
                    }
                    $bytesRead += strlen($chunk);
                    hash_update($crc, $chunk);
                }
            } finally {
                fclose($stream);
            }

            if ($bytesRead !== $size) {
                throw new ImportException('invalid_zip', 'ČÚZK ZIP entry size differs from its archive metadata.');
            }
            $expectedCrc = sprintf('%08x', (int) ($stat['crc'] ?? -1));
            if (!hash_equals($expectedCrc, hash_final($crc))) {
                throw new ImportException('invalid_zip', 'ČÚZK ZIP entry CRC validation failed.');
            }

            return new self($path, $name, $size);
        } finally {
            $zip->close();
        }
    }

    public function streamUri(): string
    {
        return 'zip://' . $this->path . '#' . $this->entryName;
    }
}
