<?php

namespace App\Ai\Tools\MySQLDatabase;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Services\MySQL\QueryExecutionException;
use App\Ai\Services\MySQL\QueryExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Single-call probe for MySQL server settings that affect how the
 * agent should write queries and interpret results: version,
 * `lower_case_table_names` (identifier case folding), `sql_mode`,
 * `time_zone`, and character set. Small, cheap, and read-only.
 */
class ServerInfoTool implements DisplayableTool, Tool
{
    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Read MySQL server info';
    }

    public function description(): Stringable|string
    {
        return 'Return MySQL server metadata that influences how queries should be written: version string, `lower_case_table_names` (identifier case folding), `sql_mode`, `time_zone`, default `character_set_database`, and the connected user. Call this once per session if identifier casing, timezones, or strict-mode behaviour might matter to the user\'s question.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $tokenString = is_string($token) ? $token : null;

        try {
            $pdo = $this->service->getConnection($tokenString);

            $rows = $this->service->run($pdo, <<<'SQL'
                SELECT @@version                   AS version,
                       @@version_comment           AS version_comment,
                       @@hostname                  AS hostname,
                       @@lower_case_table_names    AS lower_case_table_names,
                       @@sql_mode                  AS sql_mode,
                       @@time_zone                 AS time_zone,
                       @@system_time_zone          AS system_time_zone,
                       @@character_set_database    AS character_set_database,
                       @@collation_database        AS collation_database,
                       CURRENT_USER()              AS current_user
            SQL);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        $info = $rows[0] ?? [];

        return json_encode([
            'server' => $info,
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool.')
                ->required(),
        ];
    }
}
