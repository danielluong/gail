<?php

namespace App\Ai\Tools\MySQLDatabase;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Services\MySQL\QueryExecutionException;
use App\Ai\Services\MySQL\QueryExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListTablesTool implements DisplayableTool, Tool
{
    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Listed MySQL tables';
    }

    public function description(): Stringable|string
    {
        return 'List every base table and view in the connected database along with row-count estimates and engine. Use this to orient yourself before drilling into a specific table. Requires a valid `connection_token` from ConnectToDatabaseTool.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $tokenString = is_string($token) ? $token : null;

        try {
            $pdo = $this->service->getConnection($tokenString);
            $database = $this->service->resolveDatabase($tokenString);

            $tables = $this->service->run($pdo, <<<'SQL'
                SELECT TABLE_NAME AS name,
                       TABLE_TYPE AS type,
                       ENGINE AS engine,
                       TABLE_ROWS AS row_estimate,
                       ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
                  FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = :schema
                 ORDER BY TABLE_NAME
            SQL, ['schema' => $database]);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        return json_encode([
            'database' => $database,
            'count' => count($tables),
            'row_estimate_note' => 'row_estimate is INFORMATION_SCHEMA.TABLES.TABLE_ROWS, which is statistical for InnoDB and can be off by 50% or more. Use COUNT(*) when exactness matters.',
            'tables' => $tables,
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool. Identifies which database connection to use.')
                ->required(),
        ];
    }
}
