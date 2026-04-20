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
 * Cross-table column search. Given a column name or MySQL LIKE pattern
 * (`user_id`, `%email%`, `created_at`), returns every table in the
 * connected database whose columns match, along with each column's
 * data type. Saves the agent from issuing N DescribeTable calls just
 * to find where a concept lives.
 *
 * The pattern is passed as a PDO bound parameter, so it's safe to
 * accept `%` and `_` wildcards from the user directly.
 */
class FindColumnsTool implements DisplayableTool, Tool
{
    private const MAX_RESULTS = 200;

    private const MAX_PATTERN_LENGTH = 100;

    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Searched MySQL columns';
    }

    public function description(): Stringable|string
    {
        return 'Find every table in the connected database that has a column matching a given name or MySQL LIKE pattern. Use this to answer "where is `user_id` used?" or "which tables have an `email` column?" in one call instead of describing each table individually. The pattern accepts SQL wildcards (`%` for any substring, `_` for a single character); an exact column name like `user_id` matches only columns literally called `user_id`. Results are capped at 200 rows and ordered by table name.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $pattern = trim((string) ($request['pattern'] ?? ''));

        if ($pattern === '') {
            return 'Error: a column name or LIKE pattern is required.';
        }

        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return 'Error: pattern is too long. Keep it under '.self::MAX_PATTERN_LENGTH.' characters.';
        }

        $tokenString = is_string($token) ? $token : null;

        try {
            $pdo = $this->service->getConnection($tokenString);
            $database = $this->service->resolveDatabase($tokenString);

            $rows = $this->service->run($pdo, <<<'SQL'
                SELECT TABLE_NAME AS `table`,
                       COLUMN_NAME AS `column`,
                       COLUMN_TYPE AS `type`,
                       IS_NULLABLE AS nullable,
                       COLUMN_KEY AS `key`
                  FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :schema
                   AND COLUMN_NAME LIKE :pattern
                 ORDER BY TABLE_NAME, ORDINAL_POSITION
                 LIMIT 200
            SQL, ['schema' => $database, 'pattern' => $pattern]);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        return json_encode([
            'database' => $database,
            'pattern' => $pattern,
            'match_count' => count($rows),
            'truncated' => count($rows) >= self::MAX_RESULTS,
            'matches' => $rows,
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool.')
                ->required(),
            'pattern' => $schema->string()
                ->description('Column name or MySQL LIKE pattern. `user_id` matches only columns literally called "user_id"; `%email%` matches any column whose name contains "email"; `created_%` matches any column starting with "created_". Passed as a bound parameter, so wildcard characters are safe.')
                ->required(),
        ];
    }
}
