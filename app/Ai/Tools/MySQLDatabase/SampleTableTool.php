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
 * Return a small preview of rows from a single table. The SQL is always
 * `SELECT * FROM `<table>` LIMIT <n>` — no joins, no WHERE, no ORDER BY —
 * and both the table identifier and the row cap are validated before any
 * bytes leave the app. Execution goes through QueryExecutionService, so
 * it inherits the same safety validator, read-only connection handling,
 * and error shaping as every other MySQL tool.
 */
class SampleTableTool implements DisplayableTool, Tool
{
    private const DEFAULT_LIMIT = 10;

    private const MAX_LIMIT = 50;

    /**
     * Cell values longer than this (in bytes) are replaced with a
     * short placeholder so a single BLOB/TEXT/JSON column cannot blow
     * the token budget on a "just show me a few rows" call. The
     * threshold is generous — normal VARCHAR content comes through
     * untouched — and elision is only applied to strings.
     */
    private const CELL_BYTE_LIMIT = 200;

    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Sampled MySQL table';
    }

    public function description(): Stringable|string
    {
        return 'Return a small sample of rows from a table to preview its structure and data. The generated SQL is always `SELECT * FROM <table> LIMIT <n>` — no joins, no WHERE, no ORDER BY. The table name is validated against a strict identifier pattern and the row cap is clamped to [1, 50], so this is the safest way to peek at unfamiliar data. Use it before writing richer SELECTs against tables whose shape you have not yet confirmed.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $table = trim((string) ($request['table'] ?? ''));
        $limit = $this->resolveLimit($request['limit'] ?? null);

        if ($table === '') {
            return 'Error: table name is required.';
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return 'Error: table name may only contain letters, digits, and underscores.';
        }

        $query = 'SELECT * FROM `'.$table.'` LIMIT '.$limit;

        try {
            $result = $this->service->executeSelect(is_string($token) ? $token : null, $query, $limit);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        return json_encode([
            'table' => $table,
            'row_count' => $result['row_count'],
            'column_count' => $result['column_count'],
            'rows' => $this->elideLargeCells($result['rows']),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Replace any string cell whose byte length exceeds
     * {@see self::CELL_BYTE_LIMIT} with a short placeholder. Keeps
     * BLOB/TEXT/JSON values from blowing the model's token budget on
     * a sample call, while leaving normal VARCHAR content intact.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function elideLargeCells(array $rows): array
    {
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                if (is_string($value) && strlen($value) > self::CELL_BYTE_LIMIT) {
                    $rows[$index][$column] = '<elided '.strlen($value).' bytes>';
                }
            }
        }

        return $rows;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool.')
                ->required(),
            'table' => $schema->string()
                ->description('Exact table name to sample. Only letters, digits, and underscores are accepted; anything else is rejected before a connection is opened. The value is interpolated into a backtick-quoted identifier, never a bound parameter, so strict validation is mandatory.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Optional row cap. Defaults to 10, clamped to [1, 50]. The tool will never return more than 50 rows regardless of what is requested.')
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
