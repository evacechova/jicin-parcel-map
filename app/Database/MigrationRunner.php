<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    private const STATEMENT_SEPARATOR = '-- migrate:statement';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->ensureMigrationTable();
        $applied = $this->appliedMigrations();
        $completed = [];

        foreach ($this->migrationFiles('up') as $version => $path) {
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new RuntimeException(sprintf('Cannot checksum migration %s.', $version));
            }

            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new RuntimeException(sprintf('Applied migration %s has changed.', $version));
                }

                continue;
            }

            $this->executeFile($path);
            $statement = $this->pdo->prepare(
                'INSERT INTO schema_migration (version, checksum, applied_at) VALUES (:version, :checksum, CURRENT_TIMESTAMP(6))',
            );
            $statement->execute(['version' => $version, 'checksum' => $checksum]);
            $completed[] = $version;
        }

        return $completed;
    }

    public function rollback(): ?string
    {
        $this->ensureMigrationTable();
        $version = $this->pdo->query(
            'SELECT version FROM schema_migration ORDER BY applied_at DESC, version DESC LIMIT 1',
        )->fetchColumn();

        if ($version === false) {
            return null;
        }

        $downFiles = $this->migrationFiles('down');
        if (!isset($downFiles[$version])) {
            throw new RuntimeException(sprintf('Missing rollback migration for %s.', $version));
        }

        $this->executeFile($downFiles[$version]);
        $statement = $this->pdo->prepare('DELETE FROM schema_migration WHERE version = :version');
        $statement->execute(['version' => $version]);

        return $version;
    }

    /** @return array<string, bool> */
    public function status(): array
    {
        $this->ensureMigrationTable();
        $applied = $this->appliedMigrations();
        $status = [];

        foreach ($this->migrationFiles('up') as $version => $_path) {
            $status[$version] = isset($applied[$version]);
        }

        return $status;
    }

    private function ensureMigrationTable(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migration (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB
            SQL);
    }

    /** @return array<string, string> */
    private function appliedMigrations(): array
    {
        $rows = $this->pdo->query('SELECT version, checksum FROM schema_migration')->fetchAll();
        $applied = [];

        foreach ($rows as $row) {
            $applied[$row['version']] = $row['checksum'];
        }

        return $applied;
    }

    /** @return array<string, string> */
    private function migrationFiles(string $direction): array
    {
        $paths = glob($this->directory . '/*.' . $direction . '.sql');
        if ($paths === false) {
            throw new RuntimeException('Cannot read migration directory.');
        }

        $migrations = [];
        foreach ($paths as $path) {
            $filename = basename($path);
            if (preg_match('/^(\d+_[a-z0-9_-]+)\.' . $direction . '\.sql$/D', $filename, $matches) !== 1) {
                throw new RuntimeException(sprintf('Invalid migration filename %s.', $filename));
            }

            $migrations[$matches[1]] = $path;
        }

        ksort($migrations, SORT_STRING);

        return $migrations;
    }

    private function executeFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException(sprintf('Cannot read migration %s.', basename($path)));
        }

        $statements = array_filter(
            array_map('trim', explode(self::STATEMENT_SEPARATOR, $sql)),
            static fn (string $statement): bool => $statement !== '',
        );

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
