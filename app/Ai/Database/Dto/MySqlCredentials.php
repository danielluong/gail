<?php

namespace App\Ai\Database\Dto;

use InvalidArgumentException;

/**
 * Validated MySQL connection parameters. Constructed from raw tool
 * input; rejects obviously malformed values before we attempt to open
 * a socket so the model gets a fast, actionable error instead of a
 * low-level driver exception.
 */
final class MySqlCredentials
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $database,
    ) {
        if ($host === '') {
            throw new InvalidArgumentException('host is required.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('port must be between 1 and 65535.');
        }

        if ($username === '') {
            throw new InvalidArgumentException('username is required.');
        }

        if ($database === '') {
            throw new InvalidArgumentException('database is required.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            host: trim((string) ($data['host'] ?? '')),
            port: (int) ($data['port'] ?? 3306),
            username: trim((string) ($data['username'] ?? '')),
            password: (string) ($data['password'] ?? ''),
            database: trim((string) ($data['database'] ?? '')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'database' => $this->database,
        ];
    }
}
