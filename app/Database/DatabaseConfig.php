<?php

declare(strict_types=1);

namespace App\Database;

use InvalidArgumentException;

final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
    ) {
        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('Database port must be between 1 and 65535.');
        }

        if (preg_match('/^[A-Za-z0-9_]+$/D', $this->name) !== 1) {
            throw new InvalidArgumentException('Database name may contain only letters, numbers and underscores.');
        }

        if ($this->host === '' || $this->user === '') {
            throw new InvalidArgumentException('Database host and user must not be empty.');
        }
    }

    public static function fromEnvironment(string $prefix = 'DB_'): self
    {
        $port = self::read($prefix . 'PORT', '3306');

        if (filter_var($port, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Database port must be an integer.');
        }

        return new self(
            self::read($prefix . 'HOST', '127.0.0.1'),
            (int) $port,
            self::read($prefix . 'NAME'),
            self::read($prefix . 'USER'),
            self::read($prefix . 'PASSWORD', ''),
        );
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->name,
        );
    }

    private static function read(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new InvalidArgumentException(sprintf('Missing required environment variable %s.', $key));
        }

        return (string) $value;
    }
}
