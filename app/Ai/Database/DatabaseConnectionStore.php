<?php

namespace App\Ai\Database;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Token-addressed cache for database credentials collected during an
 * agent conversation. The model receives only the opaque token and
 * passes it to subsequent tool calls; the raw credentials never leave
 * the server. Entries expire so a stale conversation cannot resurrect a
 * long-lived handle days later.
 *
 * Engine-agnostic on purpose — the stored payload is treated as opaque.
 * MySQL credentials are shaped and validated by MySqlCredentials.
 */
class DatabaseConnectionStore
{
    private const TTL_SECONDS = 3600;

    private const CACHE_KEY_PREFIX = 'ai:db:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function store(array $credentials, string $engine): string
    {
        $token = (string) Str::ulid();

        $payload = Crypt::encryptString(json_encode([
            'engine' => $engine,
            'credentials' => $credentials,
            'issued_at' => time(),
        ], JSON_THROW_ON_ERROR));

        $this->cache->put($this->key($token), $payload, self::TTL_SECONDS);

        return $token;
    }

    /**
     * @return array{engine: string, credentials: array<string, mixed>, issued_at: int}|null
     */
    public function resolve(string $token): ?array
    {
        $payload = $this->cache->get($this->key($token));

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($payload);
            $decoded = json_decode($decrypted, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['engine'], $decoded['credentials'], $decoded['issued_at'])) {
            return null;
        }

        return $decoded;
    }

    public function forget(string $token): void
    {
        $this->cache->forget($this->key($token));
    }

    public function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }

    private function key(string $token): string
    {
        return self::CACHE_KEY_PREFIX.$token;
    }
}
