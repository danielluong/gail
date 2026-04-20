<?php

namespace App\Ai\Tools\MySQLDatabase;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Services\MySQL\QueryExecutionException;
use App\Ai\Services\MySQL\QueryExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class ExplainQueryTool implements DisplayableTool, Tool
{
    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Explained a MySQL query';
    }

    public function description(): Stringable|string
    {
        return "Return MySQL's execution plan for a SELECT query using EXPLAIN FORMAT=JSON, plus the tabular EXPLAIN output. Use this before running a complex query to catch full table scans, missing indexes, or expensive joins. The wrapped query itself is validated as read-only before EXPLAIN runs.";
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Error: query is required.';
        }

        try {
            $verdict = $this->service->preflight($query);

            if ($verdict->firstKeyword !== 'SELECT') {
                return 'Error: EXPLAIN is only supported for SELECT statements here.';
            }

            $pdo = $this->service->getConnection(is_string($token) ? $token : null);
            $stripped = rtrim($query, "; \t\n\r\0\x0B");

            $tabular = $this->service->run($pdo, 'EXPLAIN '.$stripped);
            $planRows = $this->service->run($pdo, 'EXPLAIN FORMAT=JSON '.$stripped);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        $planRaw = $planRows[0] ?? [];
        $planJson = is_array($planRaw) && $planRaw !== [] ? (string) reset($planRaw) : null;
        $decoded = null;

        if (is_string($planJson)) {
            try {
                $decoded = json_decode($planJson, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $decoded = null;
            }
        }

        return json_encode([
            'query' => $stripped,
            'tabular' => $tabular,
            'plan' => $decoded ?? $planJson,
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool.')
                ->required(),
            'query' => $schema->string()
                ->description('The SELECT statement to explain. Write/DDL statements are rejected.')
                ->required(),
        ];
    }
}
