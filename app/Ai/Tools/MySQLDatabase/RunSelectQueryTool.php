<?php

namespace App\Ai\Tools\MySQLDatabase;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Services\MySQL\QueryExecutionException;
use App\Ai\Services\MySQL\QueryExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RunSelectQueryTool implements DisplayableTool, Tool
{
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 500;

    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Ran a MySQL query';
    }

    public function description(): Stringable|string
    {
        return 'Execute the read-only SELECT/SHOW/DESCRIBE/EXPLAIN statement passed in the `query` parameter and return its rows. The `query` parameter is mandatory — a `connection_token` on its own is not a valid call. The query is validated against a safety layer that blocks every write/DDL/DCL statement, multiple statements, and SELECT ... INTO OUTFILE regardless of DB permissions. A LIMIT is injected automatically when you omit one; the result set is capped at 500 rows. If your query is ambiguous or its safety is unclear, ask the user for clarification instead of running it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $query = trim((string) ($request['query'] ?? ''));
        $limit = $this->resolveLimit($request['limit'] ?? null);

        if ($query === '') {
            return 'Error: the `query` parameter is required. Pass the full SELECT/SHOW/DESCRIBE/EXPLAIN statement as the `query` field of this tool call — the `connection_token` alone is not enough. Retry with both fields populated.';
        }

        $tokenString = is_string($token) ? $token : null;

        try {
            $verdict = $this->service->preflight($query);
            $result = $this->service->executeSelect($tokenString, $query, $limit);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        $truncated = $result['row_count'] >= $result['limit'];

        $payload = [
            'executed_sql' => $result['executed_sql'],
            'row_count' => $result['row_count'],
            'column_count' => $result['column_count'],
            'rows' => $result['rows'],
            'truncated' => $truncated,
        ];

        if ($truncated && $verdict->firstKeyword === 'SELECT') {
            $estimate = $this->estimateFullRowCount($tokenString, $query);

            if ($estimate !== null) {
                $payload['estimated_total_rows'] = $estimate;
                $payload['estimated_total_rows_note'] = 'EXPLAIN upper estimate — MySQL\'s optimizer approximation, not an exact count. Raise the `limit` parameter or add an explicit LIMIT to narrow the result.';
            }
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Run `EXPLAIN <query>` on the user's original SQL and return the
     * largest `rows` estimate across plan rows — the pessimistic upper
     * bound on how many rows the query would scan without the tool's
     * injected LIMIT. Swallows any error; a failed estimate is
     * strictly worse than no estimate, so the primary result always
     * wins.
     */
    private function estimateFullRowCount(?string $token, string $query): ?int
    {
        try {
            $pdo = $this->service->getConnection($token);
            $stripped = rtrim($query, "; \t\n\r\0\x0B");
            $plan = $this->service->run($pdo, 'EXPLAIN '.$stripped);
        } catch (QueryExecutionException) {
            return null;
        }

        $max = 0;

        foreach ($plan as $row) {
            $rows = (int) ($row['rows'] ?? 0);

            if ($rows > $max) {
                $max = $rows;
            }
        }

        return $max > 0 ? $max : null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool.')
                ->required(),
            'query' => $schema->string()
                ->description('The SQL to run. Must be a single SELECT, SHOW, DESCRIBE, or EXPLAIN statement. Write/DDL keywords (INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, CREATE, GRANT, etc.) are rejected before the query reaches the server.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Optional row cap. Defaults to 100, max 500. A LIMIT is added to the query when the original does not contain one.')
                ->required()
                ->nullable(),
        ];
    }

    private function resolveLimit(mixed $input): int
    {
        if (! is_numeric($input)) {
            return self::DEFAULT_LIMIT;
        }

        $value = (int) $input;

        if ($value < 1) {
            return 1;
        }

        return min($value, self::MAX_LIMIT);
    }
}
