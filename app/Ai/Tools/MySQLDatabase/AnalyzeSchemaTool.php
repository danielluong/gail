<?php

namespace App\Ai\Tools\MySQLDatabase;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Services\MySQL\QueryExecutionException;
use App\Ai\Services\MySQL\QueryExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use PDO;
use Stringable;

class AnalyzeSchemaTool implements DisplayableTool, Tool
{
    private const LARGE_TABLE_THRESHOLD_MB = 100;

    private const LARGE_TABLE_ROW_THRESHOLD = 1_000_000;

    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Analyzed MySQL schema';
    }

    public function description(): Stringable|string
    {
        return 'Produce a high-level summary of the connected database: largest tables, relationships between tables, and tables without any secondary index. Use this to answer "what does this database look like?" before drilling into individual tables.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $tokenString = is_string($token) ? $token : null;

        try {
            $pdo = $this->service->getConnection($tokenString);
            $database = $this->service->resolveDatabase($tokenString);

            $tables = $this->fetchTables($pdo, $database);
            $relationships = $this->fetchRelationships($pdo, $database);
            $unindexedTables = $this->fetchUnindexedTables($pdo, $database);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        $largeTables = array_values(array_filter(
            $tables,
            fn (array $t) => ((float) ($t['size_mb'] ?? 0)) >= self::LARGE_TABLE_THRESHOLD_MB
                || ((int) ($t['row_estimate'] ?? 0)) >= self::LARGE_TABLE_ROW_THRESHOLD,
        ));

        return json_encode([
            'database' => $database,
            'summary' => [
                'table_count' => count($tables),
                'total_size_mb' => array_sum(array_map(fn ($t) => (float) ($t['size_mb'] ?? 0), $tables)),
                'relationship_count' => count($relationships),
            ],
            'large_tables' => $largeTables,
            'relationships' => $relationships,
            'tables_missing_secondary_indexes' => $unindexedTables,
            'note' => 'Row counts from INFORMATION_SCHEMA.TABLES are estimates on InnoDB and may drift from reality.',
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

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTables(PDO $connection, string $database): array
    {
        return $this->service->run($connection, <<<'SQL'
            SELECT TABLE_NAME AS name,
                   TABLE_ROWS AS row_estimate,
                   ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb,
                   ENGINE AS engine
              FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
        SQL, ['schema' => $database]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRelationships(PDO $connection, string $database): array
    {
        return $this->service->run($connection, <<<'SQL'
            SELECT TABLE_NAME AS `from_table`,
                   COLUMN_NAME AS `from_column`,
                   REFERENCED_TABLE_NAME AS `to_table`,
                   REFERENCED_COLUMN_NAME AS `to_column`,
                   CONSTRAINT_NAME AS constraint_name
              FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = :schema
               AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION
        SQL, ['schema' => $database]);
    }

    /**
     * @return list<string>
     */
    private function fetchUnindexedTables(PDO $connection, string $database): array
    {
        $rows = $this->service->run($connection, <<<'SQL'
            SELECT t.TABLE_NAME
              FROM INFORMATION_SCHEMA.TABLES t
              LEFT JOIN INFORMATION_SCHEMA.STATISTICS s
                ON t.TABLE_SCHEMA = s.TABLE_SCHEMA
               AND t.TABLE_NAME = s.TABLE_NAME
               AND s.INDEX_NAME <> 'PRIMARY'
             WHERE t.TABLE_SCHEMA = :schema
               AND t.TABLE_TYPE = 'BASE TABLE'
             GROUP BY t.TABLE_NAME
            HAVING COUNT(s.INDEX_NAME) = 0
             ORDER BY t.TABLE_NAME
        SQL, ['schema' => $database]);

        return array_column($rows, 'TABLE_NAME');
    }
}
