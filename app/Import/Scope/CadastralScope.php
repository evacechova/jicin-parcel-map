<?php

declare(strict_types=1);

namespace App\Import\Scope;

use App\Import\ImportException;

final readonly class CadastralScope
{
    private const JICIN_BASE_URL = 'https://services.cuzk.gov.cz/gml/inspire/cp/epsg-5514';
    private const JICIN_EXPECTED_COUNT = 240;

    /** @param array<int, string> $territories PHP normalises six-digit numeric keys to integers. */
    private function __construct(
        public string $code,
        public string $sourceBaseUrl,
        public array $territories,
    ) {
    }

    public static function load(string $code, string $projectRoot): self
    {
        if ($code !== 'jicin') {
            throw new ImportException('unsupported_scope', sprintf('Unsupported scope "%s".', $code));
        }

        $path = $projectRoot . '/config/scopes/jicin.csv';
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ImportException('scope_unreadable', 'Cannot read the Jičín scope configuration.');
        }

        try {
            $header = fgetcsv($handle, separator: ';', escape: '');
            if ($header !== ['ku_code', 'name']) {
                throw new ImportException('invalid_scope', 'The Jičín scope header is invalid.');
            }

            $territories = [];
            $line = 1;
            while (($row = fgetcsv($handle, separator: ';', escape: '')) !== false) {
                ++$line;
                if ($row === [null] || $row === []) {
                    continue;
                }

                if (count($row) !== 2 || preg_match('/^[0-9]{6}$/D', $row[0]) !== 1 || trim($row[1]) === '') {
                    throw new ImportException('invalid_scope', sprintf('Invalid Jičín scope row at line %d.', $line));
                }

                if (isset($territories[$row[0]])) {
                    throw new ImportException('duplicate_scope_code', sprintf('Duplicate KÚ code %s.', $row[0]));
                }

                $territories[$row[0]] = $row[1];
            }
        } finally {
            fclose($handle);
        }

        if (count($territories) !== self::JICIN_EXPECTED_COUNT) {
            throw new ImportException(
                'invalid_scope_count',
                sprintf('The Jičín scope must contain exactly %d KÚ.', self::JICIN_EXPECTED_COUNT),
            );
        }

        $sorted = $territories;
        ksort($sorted, SORT_STRING);
        if ($sorted !== $territories) {
            throw new ImportException('invalid_scope_order', 'The Jičín scope must be sorted by KÚ code.');
        }

        return new self($code, self::JICIN_BASE_URL, $territories);
    }

    public function downloadUrl(string $kuCode): string
    {
        if (!isset($this->territories[$kuCode])) {
            throw new ImportException('ku_outside_scope', sprintf('KÚ code %s is not in scope %s.', $kuCode, $this->code));
        }

        $url = $this->sourceBaseUrl . '/' . rawurlencode($kuCode) . '.zip';
        $parts = parse_url($url);
        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'services.cuzk.gov.cz'
            || preg_match('#^/gml/inspire/cp/epsg-5514/[0-9]{6}\.zip$#D', $parts['path'] ?? '') !== 1
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new ImportException('invalid_source_url', 'Generated ČÚZK source URL failed validation.');
        }

        return $url;
    }

    /** @return list<string> */
    public function territoryCodes(): array
    {
        return array_map(static fn (int $code): string => (string) $code, array_keys($this->territories));
    }
}
