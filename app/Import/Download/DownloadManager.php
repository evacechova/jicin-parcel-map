<?php

declare(strict_types=1);

namespace App\Import\Download;

use App\Import\ImportException;
use Closure;

final class DownloadManager
{
    private const ATTEMPT_DELAYS_SECONDS = [0, 1, 3];

    /** @param null|Closure(int): void $sleeper */
    public function __construct(
        private readonly DownloadTransport $transport,
        private readonly ?Closure $sleeper = null,
    ) {
    }

    /** @param Closure(string): void $integrityCheck */
    public function download(string $url, string $target, Closure $integrityCheck): DownloadedFile
    {
        if (file_exists($target)) {
            throw new ImportException('download_target_exists', 'The run-local download target already exists.');
        }

        $directory = dirname($target);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new ImportException('download_directory_error', 'The run-local download directory is not writable.');
        }

        $nextDelay = 0;
        foreach (self::ATTEMPT_DELAYS_SECONDS as $index => $_configuredDelay) {
            $attempt = $index + 1;
            if ($nextDelay > 0) {
                ($this->sleeper ?? sleep(...))($nextDelay);
            }

            $part = sprintf('%s.attempt-%d.part', $target, $attempt);
            if (file_exists($part) && !unlink($part)) {
                throw new ImportException('download_file_error', 'Cannot remove a stale temporary download file.');
            }

            try {
                $result = $this->transport->fetch($url, $part);
            } catch (\Throwable $exception) {
                self::removePartial($part);
                throw $exception;
            }
            $isSuccessfulHttp = $result->httpStatus !== null
                && $result->httpStatus >= 200
                && $result->httpStatus < 300;

            if ($isSuccessfulHttp && $result->bytesWritten > 0) {
                try {
                    $integrityCheck($part);
                } catch (\Throwable $exception) {
                    self::removePartial($part);
                    if ($exception instanceof ImportException) {
                        throw $exception;
                    }

                    throw new ImportException('download_integrity_error', 'Downloaded ZIP failed integrity validation.', $exception);
                }

                if (!rename($part, $target)) {
                    self::removePartial($part);
                    throw new ImportException('download_rename_error', 'Cannot publish the verified run-local ZIP.');
                }

                $checksum = hash_file('sha256', $target);
                $size = filesize($target);
                if ($checksum === false || $size === false) {
                    throw new ImportException('download_metadata_error', 'Cannot record downloaded ZIP metadata.');
                }

                return new DownloadedFile($target, $attempt, $result->httpStatus, $size, $checksum);
            }

            self::removePartial($part);
            $retryable = $result->curlError !== null
                ? self::isRetryableCurlError($result->curlError)
                : self::isRetryableHttpStatus($result->httpStatus);

            if (!$retryable || $attempt === count(self::ATTEMPT_DELAYS_SECONDS)) {
                $code = $result->curlError !== null ? 'download_transport_error' : 'download_http_error';
                $detail = $result->curlError !== null
                    ? sprintf('cURL error %d', $result->curlError)
                    : sprintf('HTTP status %d', $result->httpStatus ?? 0);
                throw new ImportException($code, sprintf('ČÚZK download failed after %d attempt(s): %s.', $attempt, $detail));
            }

            $nextDelay = $result->retryAfterSeconds
                ?? self::ATTEMPT_DELAYS_SECONDS[$attempt];
        }

        throw new ImportException('download_failed', 'ČÚZK download failed.');
    }

    private static function isRetryableHttpStatus(?int $status): bool
    {
        return $status === 408 || $status === 429 || ($status !== null && $status >= 500 && $status <= 599);
    }

    private static function isRetryableCurlError(int $error): bool
    {
        $retryable = [
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEDOUT,
            CURLE_PARTIAL_FILE,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
            CURLE_GOT_NOTHING,
            CURLE_SSL_CONNECT_ERROR,
        ];
        foreach (['CURLE_HTTP2', 'CURLE_HTTP2_STREAM'] as $optionalConstant) {
            if (defined($optionalConstant)) {
                $retryable[] = constant($optionalConstant);
            }
        }

        return in_array($error, $retryable, true);
    }

    private static function removePartial(string $path): void
    {
        if (file_exists($path) && !unlink($path)) {
            throw new ImportException('download_file_error', 'Cannot remove a partial download file.');
        }
    }
}
