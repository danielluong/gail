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

class DescribeTableTool implements DisplayableTool, Tool
{
    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Described MySQL table';
    }

    public function description(): Stringable|string
    {
        return 'Return column definitions, indexes, and foreign keys for a single table. Use this before writing SELECTs against an unfamiliar table so you reference real columns and types. Table names must match INFORMATION_SCHEMA entries exactly.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $table = trim((string) ($request['table'] ?? ''));

        if ($table === '') {
            return 'Error: table name is required.';
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return 'Error: table name may only contain letters, digits, and underscores.';
        }

        $tokenString = is_string($token) ? $token : null;

        try {
            $pdo = $this->service->getConnection($tokenString);
            $database = $this->service->resolveDatabase($tokenString);

            $columns = $this->service->run($pdo, <<<'SQL'
                SELECT COLUMN_NAME AS name,
                       COLUMN_TYPE AS type,
                       IS_NULLABLE AS nullable,
                       COLUMN_KEY AS `key`,
                       COLUMN_DEFAULT AS `default`,
                       EXTRA AS extra,
                       COLUMN_COMMENT AS comment
                  FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name
                 ORDER BY ORDINAL_POSITION
            SQL, ['schema' => $database, 'name' => $table]);

            if ($columns === []) {
                $suggestion = $this->suggestSimilarTable($pdo, $database, $table);

                return "Error: table '{$table}' not found in database '{$database}'.".$suggestion;
            }

            $indexes = $this->service->run($pdo, <<<'SQL'
                SELECT INDEX_NAME AS name,
                       NON_UNIQUE AS non_unique,
                       COLUMN_NAME AS column_name,
                       SEQ_IN_INDEX AS position,
                       INDEX_TYPE AS type
                  FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name
                 ORDER BY INDEX_NAME, SEQ_IN_INDEX
            SQL, ['schema' => $database, 'name' => $table]);

            $foreignKeys = $this->service->run($pdo, <<<'SQL'
                SELECT CONSTRAINT_NAME AS name,
                       COLUMN_NAME AS column_name,
                       REFERENCED_TABLE_NAME AS references_table,
                       REFERENCED_COLUMN_NAME AS references_column
                  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = :schema
                   AND TABLE_NAME = :name
                   AND REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION
            SQL, ['schema' => $database, 'name' => $table]);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        return json_encode([
            'database' => $database,
            'table' => $table,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool.')
                ->required(),
            'table' => $schema->string()
                ->description('Exact table name. Case must match the server configuration. Only letters, digits, and underscores are accepted.')
                ->required(),
        ];
    }

    /**
     * Build a "did you mean …" tail for a failed lookup. Uses PHP's
     * levenshtein() on the full list of tables in the schema and
     * returns the top three matches within an edit distance that
     * scales with the input length. Wrapped in its own try/catch so a
     * metadata hiccup here never masks the real "table not found"
     * error.
     */
    private function suggestSimilarTable(PDO $pdo, string $database, string $table): string
    {
        try {
            $rows = $this->service->run($pdo, <<<'SQL'
                SELECT TABLE_NAME AS name
                  FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = :schema
            SQL, ['schema' => $database]);
        } catch (QueryExecutionException) {
            return '';
        }

        $names = array_values(array_filter(array_column($rows, 'name'), 'is_string'));

        if ($names === []) {
            return '';
        }

        $threshold = max(2, (int) ceil(strlen($table) / 3));
        $scored = [];

        foreach ($names as $name) {
            $distance = levenshtein(strtolower($table), strtolower($name));

            if ($distance <= $threshold) {
                $scored[$name] = $distance;
            }
        }

        if ($scored === []) {
            return '';
        }

        asort($scored);
        $candidates = array_slice(array_keys($scored), 0, 3);

        return ' Did you mean: '.implode(', ', $candidates).'?';
    }
}
