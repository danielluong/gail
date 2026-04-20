<?php

namespace App\Ai\Tools\MySQLDatabase;

use App\Ai\Contracts\DisplayableTool;
use App\Ai\Services\MySQL\QueryExecutionException;
use App\Ai\Services\MySQL\QueryExecutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use PDO;
use Stringable;
use Throwable;

/**
 * Export a read-only SELECT result set to a CSV file on the public disk
 * and hand back a markdown download link for the chat UI. Routes every
 * DB interaction through QueryExecutionService so the safety validator,
 * ambiguity linter, and token resolution behave identically to
 * RunSelectQueryTool. Row caps are higher than the inline query tool
 * (exports legitimately span thousands of rows) but still bounded so a
 * runaway query cannot fill the disk.
 */
class ExportQueryCsvTool implements DisplayableTool, Tool
{
    private const DISK = 'public';

    private const DIRECTORY = 'ai-exports';

    private const DEFAULT_LIMIT = 10000;

    private const MAX_LIMIT = 50000;

    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Exported a CSV';
    }

    public function description(): Stringable|string
    {
        return 'Run the SELECT passed in the `query` parameter and write its full result set to a downloadable CSV file. The `query` parameter is mandatory — a `connection_token` on its own is not a valid call. Use this tool when the user asks to export, download, save, or share query results as a file. The query is validated against the same safety layer as RunSelectQueryTool (write/DDL/DCL and multi-statement payloads are refused). A LIMIT is injected when the query omits one; the default cap is 10,000 rows and the absolute cap is 50,000. Returns a markdown link the user can click to download the file.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $query = trim((string) ($request['query'] ?? ''));
        $limit = $this->resolveLimit($request['limit'] ?? null);
        $filename = $this->resolveFilename($request['filename'] ?? null);

        if ($query === '') {
            return 'Error: the `query` parameter is required. Pass the full SELECT statement as the `query` field of this tool call — the `connection_token` alone is not enough. Retry with both fields populated.';
        }

        try {
            $verdict = $this->service->preflight($query);

            if ($verdict->firstKeyword !== 'SELECT') {
                return 'Error: CSV export only supports SELECT statements. Use RunSelectQueryTool for SHOW/DESCRIBE/EXPLAIN.';
            }

            $pdo = $this->service->getConnection(is_string($token) ? $token : null);
            $queryToRun = $this->service->applyLimit($query, $limit);
            $rows = $this->fetchRows($pdo, $queryToRun, $limit);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        $storedName = Str::random(12).'-'.$filename;
        $path = self::DIRECTORY.'/'.$storedName;
        $disk = Storage::disk(self::DISK);

        $disk->put($path, $this->buildCsv($rows));

        // Serve via the download route (not the raw public disk URL)
        // so every browser gets a Content-Disposition: attachment
        // response and saves the file reliably instead of trying to
        // render it inline.
        $url = route('ai-exports.show', ['filename' => $storedName]);
        $rowCount = count($rows);
        $columnCount = $rowCount > 0 ? count($rows[0]) : 0;
        $suffix = $rowCount === 1 ? 'row' : 'rows';
        $summary = "{$rowCount} {$suffix}, {$columnCount} columns";

        if ($rowCount >= $limit) {
            $summary .= " (truncated at {$limit}-row cap)";
        }

        // Return the markdown link as the primary tool output so the
        // model reproduces it verbatim in its reply. A JSON envelope
        // here would force the model to extract and re-emit the link,
        // which it frequently skipped.
        return "[Download {$filename}]({$url}) — {$summary}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool.')
                ->required(),
            'query' => $schema->string()
                ->description('The SELECT to export. Only SELECT is supported — use RunSelectQueryTool for SHOW/DESCRIBE/EXPLAIN. Write/DDL keywords are rejected by the safety layer before the query is run.')
                ->required(),
            'filename' => $schema->string()
                ->description('Optional human-readable filename for the CSV (extension optional — ".csv" is added if missing). Alphanumerics, dashes, and underscores only; anything else is stripped. Defaults to a timestamped name.')
                ->required()
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Optional row cap for the export. Defaults to 10,000, max 50,000. A LIMIT is injected into the query when the original does not contain one.')
                ->required()
                ->nullable(),
        ];
    }

    /**
     * Stream rows one at a time rather than fetching everything into
     * memory up front. Exports can legitimately return tens of
     * thousands of rows; a full fetch would spike memory. Errors are
     * lifted to QueryExecutionException so the handle() catch block
     * formats them consistently with every other kernel failure.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRows(PDO $pdo, string $query, int $limit): array
    {
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute();

            $rows = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = $row;

                if (count($rows) >= $limit) {
                    break;
                }
            }

            return $rows;
        } catch (Throwable $e) {
            throw new QueryExecutionException($this->service->handleError($e), previous: $e);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function buildCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv($handle, array_map($this->stringifyValue(...), $row));
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
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

    private function resolveFilename(mixed $input): string
    {
        $default = 'query-export-'.now()->format('Ymd-His').'.csv';

        if (! is_string($input) || trim($input) === '') {
            return $default;
        }

        $stem = preg_replace('/\.csv$/i', '', trim($input)) ?? '';
        $stem = preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem) ?? '';
        $stem = trim($stem, '-');

        if ($stem === '') {
            return $default;
        }

        return $stem.'.csv';
    }
}
