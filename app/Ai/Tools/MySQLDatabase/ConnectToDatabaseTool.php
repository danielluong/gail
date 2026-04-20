<?php

namespace App\Ai\Tools\MySQLDatabase;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Database\DatabaseConnectionStore;
use App\Ai\Database\Dto\MySqlCredentials;
use App\Ai\Database\MySqlConnectionFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Opens a MySQL connection with the supplied credentials, verifies it
 * works, and returns an opaque token the other MySQL tools consume.
 * Credentials never leave the server — the token is the only piece of
 * state the model carries between turns.
 */
class ConnectToDatabaseTool implements DisplayableTool, Tool
{
    public function __construct(
        private readonly DatabaseConnectionStore $store,
        private readonly MySqlConnectionFactory $factory,
    ) {}

    public function label(): string
    {
        return 'Connected to MySQL';
    }

    public function description(): Stringable|string
    {
        return 'Open a read-only MySQL connection for the current conversation. Supply host, port, username, password, and database. Returns a `connection_token` — pass it to every other MySQL tool. Use this before any other database tool. Tokens expire after 1 hour of inactivity; reconnect if later calls report an expired token.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $credentials = MySqlCredentials::fromArray([
                'host' => $request['host'] ?? null,
                'port' => $request['port'] ?? 3306,
                'username' => $request['username'] ?? null,
                'password' => $request['password'] ?? '',
                'database' => $request['database'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            return 'Error: '.$e->getMessage();
        }

        try {
            $pdo = $this->factory->open($credentials);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            unset($pdo);
        } catch (Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        $token = $this->store->store($credentials->toArray(), engine: 'mysql');

        return json_encode([
            'ok' => true,
            'connection_token' => $token,
            'database' => $credentials->database,
            'host' => $credentials->host,
            'port' => $credentials->port,
            'server_version' => $version,
            'ttl_seconds' => $this->store->ttlSeconds(),
            'note' => 'Connection is read-only at the session level — writes are blocked regardless of the DB user permissions.',
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'host' => $schema->string()
                ->description('MySQL server hostname or IP, e.g. "127.0.0.1" or "db.internal".')
                ->required(),
            'port' => $schema->integer()
                ->description('MySQL TCP port. Defaults to 3306.')
                ->required()
                ->nullable(),
            'username' => $schema->string()
                ->description('Username to authenticate as. Prefer a read-only account even though the agent also enforces read-only at the session level.')
                ->required(),
            'password' => $schema->string()
                ->description('Password for the MySQL user. May be empty when authenticating with an empty password.')
                ->required()
                ->nullable(),
            'database' => $schema->string()
                ->description('Name of the MySQL schema/database to connect to.')
                ->required(),
        ];
    }
}
