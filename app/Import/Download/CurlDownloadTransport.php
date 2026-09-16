<?php

declare(strict_types=1);

namespace App\Import\Download;

use App\Import\ImportException;

final class CurlDownloadTransport implements DownloadTransport
{
    public function __construct(
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $transferTimeoutSeconds = 180,
    ) {
    }

    public function fetch(string $url, string $destination): DownloadAttemptResult
    {
        $output = fopen($destination, 'xb');
        if ($output === false) {
            throw new ImportException('download_file_error', 'Cannot create a temporary download file.');
        }

        $retryAfter = null;
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($output);
            throw new ImportException('download_init_error', 'Cannot initialize the HTTP client.');
        }

        curl_setopt_array($curl, [
            CURLOPT_FILE => $output,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->transferTimeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'jicin-parcel-map/phase-03-import',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (str_starts_with($header, 'HTTP/')) {
                    $retryAfter = null;
                }
                if (preg_match('/^Retry-After:\s*(.+)\s*$/iD', trim($header), $matches) === 1) {
                    $retryAfter = self::parseRetryAfter($matches[1]);
                }

                return strlen($header);
            },
        ]);

        try {
            $success = curl_exec($curl);
            $error = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            fclose($output);
        }

        clearstatcache(true, $destination);
        $size = filesize($destination);
        $bytesWritten = $size === false ? 0 : $size;

        if ($success === false || $error !== CURLE_OK) {
            return DownloadAttemptResult::transportFailure($error, $bytesWritten);
        }

        return DownloadAttemptResult::http($status, $retryAfter, $bytesWritten);
    }

    private static function parseRetryAfter(string $value): ?int
    {
        if (preg_match('/^[0-9]+$/D', $value) === 1) {
            return min(30, (int) $value);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return max(0, min(30, $timestamp - time()));
    }
}
