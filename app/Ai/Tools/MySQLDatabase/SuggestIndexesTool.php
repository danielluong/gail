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

/**
 * Context-aware index advisor. The previous version guessed at columns
 * by name (`*_id`, `*_at`). This version parses the query's actual
 * shape — the WHERE, JOIN ON, GROUP BY and ORDER BY column references
 * per table — and cross-references it with MySQL's EXPLAIN plan and
 * the table's existing indexes. For every plan row that is flagged
 * (full scan, no key, filesort, temporary) it proposes one composite
 * index using the leftmost-prefix ordering WHERE → JOIN → sort. The
 * output is capped at three suggestions per query and the tool never
 * issues DDL; every proposal is labeled "DO NOT APPLY WITHOUT REVIEW".
 */
class SuggestIndexesTool implements DisplayableTool, Tool
{
    private const MAX_SUGGESTIONS = 3;

    private const MAX_INDEX_COLUMNS = 4;

    /**
     * Tokens that look like identifiers but are SQL keywords. Used when
     * parsing FROM/JOIN to avoid treating `LEFT`, `INNER`, `ON`, etc.
     * as a table name or alias.
     *
     * @var list<string>
     */
    private const JOIN_KEYWORDS = [
        'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL',
        'JOIN', 'STRAIGHT_JOIN', 'ON', 'USING', 'AS',
    ];

    /**
     * Reserved words that can appear in the LHS position of a comparison
     * and must not be treated as column references.
     *
     * @var list<string>
     */
    private const COMPARISON_STOPWORDS = [
        'AND', 'OR', 'NOT', 'WHERE', 'ON', 'CASE', 'WHEN', 'THEN', 'ELSE',
        'END', 'BETWEEN', 'IN', 'IS', 'LIKE', 'NULL', 'TRUE', 'FALSE',
        'EXISTS', 'ANY', 'SOME', 'ALL', 'IF', 'SELECT',
    ];

    public function __construct(
        private readonly QueryExecutionService $service,
    ) {}

    public function label(): string
    {
        return 'Suggested MySQL indexes';
    }

    public function description(): Stringable|string
    {
        return 'Analyze a SELECT against its EXPLAIN plan and the actual columns referenced in WHERE, JOIN ON, GROUP BY, and ORDER BY. Proposes up to three composite indexes — ordered WHERE → JOIN → sort per the leftmost-prefix rule — for tables flagged by the plan (type=ALL, no key, Using filesort, Using temporary). Existing indexes that already cover a proposed column prefix are skipped. The tool is read-only; every proposed CREATE INDEX is annotated with "DO NOT APPLY WITHOUT REVIEW" so the user can vet it before running it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $token = $request['connection_token'] ?? null;
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Error: query is required.';
        }

        $tokenString = is_string($token) ? $token : null;
        $stripped = rtrim($query, "; \t\n\r\0\x0B");

        try {
            $verdict = $this->service->preflight($query);

            if ($verdict->firstKeyword !== 'SELECT') {
                return 'Error: index suggestions only apply to SELECT statements.';
            }

            $pdo = $this->service->getConnection($tokenString);
            $database = $this->service->resolveDatabase($tokenString);
            $plan = $this->service->run($pdo, 'EXPLAIN '.$stripped);
            $shape = $this->parseShape($stripped);
            $suggestions = $this->buildSuggestions($pdo, $database, $plan, $shape);
        } catch (QueryExecutionException $e) {
            return 'Error: '.$e->getMessage();
        }

        return json_encode([
            'query' => $stripped,
            'suggestions' => $suggestions,
        ], JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_token' => $schema->string()
                ->description('Token returned by ConnectToDatabaseTool.')
                ->required(),
            'query' => $schema->string()
                ->description('The SELECT statement to analyze. Only SELECT is supported; the safety layer rejects other statement types before the query is parsed.')
                ->required(),
        ];
    }

    /**
     * Parse the query into a structural shape: alias → table map plus
     * the column references found in each analyzed clause, grouped by
     * resolved table name. Unqualified identifiers resolve only when
     * the query references a single table (same rule the ambiguity
     * linter uses); otherwise they are dropped rather than guessed.
     *
     * @return array{
     *     aliases: array<string, string>,
     *     default_table: ?string,
     *     where: array<string, list<string>>,
     *     join: array<string, list<string>>,
     *     group: array<string, list<string>>,
     *     order: array<string, list<string>>,
     * }
     */
    private function parseShape(string $query): array
    {
        $normalized = $this->normalize($query);
        $aliases = $this->parseAliases($normalized);
        $defaultTable = count($aliases) === 1 ? reset($aliases) : null;

        return [
            'aliases' => $aliases,
            'default_table' => $defaultTable,
            'where' => $this->resolve($this->extractComparisonRefs($this->whereClause($normalized)), $aliases, $defaultTable),
            'join' => $this->resolve($this->extractJoinRefs($normalized), $aliases, $defaultTable),
            'group' => $this->resolve($this->extractListRefs($this->groupByClause($normalized)), $aliases, $defaultTable),
            'order' => $this->resolve($this->extractListRefs($this->orderByClause($normalized)), $aliases, $defaultTable),
        ];
    }

    /**
     * Strip comments and blank out quoted literals so regexes can
     * operate on structure without being fooled by user-supplied text.
     * Backticks are removed entirely so `col` and col resolve the same.
     */
    private function normalize(string $query): string
    {
        $query = preg_replace('/\/\*.*?\*\//s', ' ', $query) ?? $query;
        $query = preg_replace('/--[^\n\r]*/', ' ', $query) ?? $query;
        $query = preg_replace('/#[^\n\r]*/', ' ', $query) ?? $query;

        $query = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $query) ?? $query;
        $query = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '""', $query) ?? $query;

        return str_replace('`', '', $query);
    }

    /**
     * Build alias → real-table map by scanning the FROM portion for
     * `table alias`, `table AS alias`, comma-separated tables, and
     * `JOIN table alias` constructions. Anything that looks like a
     * JOIN keyword is filtered out so `LEFT`, `ON`, etc. never end
     * up masquerading as an alias.
     *
     * @return array<string, string>
     */
    private function parseAliases(string $query): array
    {
        $from = $this->fromPortion($query);

        if ($from === '') {
            return [];
        }

        $aliases = [];
        $pattern = '/(?:^|,|\bJOIN\b)\s*([A-Za-z_][A-Za-z0-9_]*)(?:\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]*))?/i';

        preg_match_all($pattern, $from, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $table = $match[1];

            if ($this->isJoinKeyword($table)) {
                continue;
            }

            $candidateAlias = isset($match[2]) ? $match[2] : $table;

            if ($this->isJoinKeyword($candidateAlias)) {
                $candidateAlias = $table;
            }

            $aliases[$candidateAlias] = $table;
        }

        return $aliases;
    }

    private function fromPortion(string $query): string
    {
        $pattern = '/\bFROM\b(.+?)(?=\b(?:WHERE|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT|UNION|INTERSECT|EXCEPT)\b|\z)/is';

        return preg_match($pattern, $query, $matches) === 1 ? $matches[1] : '';
    }

    private function whereClause(string $query): string
    {
        $pattern = '/\bWHERE\b(.+?)(?=\b(?:GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT|UNION|INTERSECT|EXCEPT)\b|\z)/is';

        return preg_match($pattern, $query, $matches) === 1 ? $matches[1] : '';
    }

    private function groupByClause(string $query): string
    {
        $pattern = '/\bGROUP\s+BY\b(.+?)(?=\b(?:HAVING|ORDER\s+BY|LIMIT|UNION|INTERSECT|EXCEPT)\b|\z)/is';

        return preg_match($pattern, $query, $matches) === 1 ? $matches[1] : '';
    }

    private function orderByClause(string $query): string
    {
        $pattern = '/\bORDER\s+BY\b(.+?)(?=\b(?:LIMIT|UNION|INTERSECT|EXCEPT)\b|\z)/is';

        return preg_match($pattern, $query, $matches) === 1 ? $matches[1] : '';
    }

    /**
     * Pull column references from a WHERE clause by matching the LHS
     * of each comparison operator. Only the LHS is indexable in the
     * conventional sense; values on the RHS are ignored by design.
     *
     * @return list<array{table: ?string, column: string}>
     */
    private function extractComparisonRefs(string $clause): array
    {
        if ($clause === '') {
            return [];
        }

        $pattern = '/(?:([A-Za-z_][A-Za-z0-9_]*)\.)?([A-Za-z_][A-Za-z0-9_]*)\s*(?:=|<=|>=|<>|!=|<|>|\bIN\b|\bNOT\s+IN\b|\bLIKE\b|\bIS\s+(?:NOT\s+)?NULL\b|\bBETWEEN\b)/i';

        preg_match_all($pattern, $clause, $matches, PREG_SET_ORDER);

        $refs = [];

        foreach ($matches as $match) {
            $column = $match[2];

            if (in_array(strtoupper($column), self::COMPARISON_STOPWORDS, true)) {
                continue;
            }

            $refs[] = [
                'table' => $match[1] !== '' ? $match[1] : null,
                'column' => $column,
            ];
        }

        return $refs;
    }

    /**
     * Pull qualified column references from every `JOIN ... ON <expr>`
     * block. Join predicates are almost always written with qualified
     * names, so the tool only extracts qualified refs here to avoid
     * false positives from bare identifiers that are actually literals
     * or aliases.
     *
     * @return list<array{table: ?string, column: string}>
     */
    private function extractJoinRefs(string $query): array
    {
        $pattern = '/\bJOIN\s+[A-Za-z_][A-Za-z0-9_]*(?:\s+(?:AS\s+)?[A-Za-z_][A-Za-z0-9_]*)?\s+ON\s+(.+?)(?=\b(?:JOIN|LEFT|RIGHT|INNER|CROSS|OUTER|WHERE|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT|UNION|INTERSECT|EXCEPT)\b|\z)/is';

        preg_match_all($pattern, $query, $matches);

        $refs = [];

        foreach ($matches[1] as $onClause) {
            preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)/', (string) $onClause, $joinColumns, PREG_SET_ORDER);

            foreach ($joinColumns as $match) {
                $refs[] = ['table' => $match[1], 'column' => $match[2]];
            }
        }

        return $refs;
    }

    /**
     * Pull column references from a comma-separated clause body
     * (GROUP BY or ORDER BY). Expressions and numeric ordinals are
     * intentionally skipped — they are not indexable on their own.
     *
     * @return list<array{table: ?string, column: string}>
     */
    private function extractListRefs(string $clause): array
    {
        if ($clause === '') {
            return [];
        }

        $refs = [];

        foreach ($this->splitTopLevel($clause) as $item) {
            $trimmed = trim($item);
            $trimmed = preg_replace('/\s+(?:ASC|DESC)\s*$/i', '', $trimmed) ?? $trimmed;

            if ($trimmed === '' || str_contains($trimmed, '(') || preg_match('/^\d+$/', $trimmed) === 1) {
                continue;
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)$/', $trimmed, $match) === 1) {
                $refs[] = ['table' => $match[1], 'column' => $match[2]];
            } elseif (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)$/', $trimmed, $match) === 1) {
                $refs[] = ['table' => null, 'column' => $match[1]];
            }
        }

        return $refs;
    }

    /**
     * Resolve each reference to a real table name: explicit aliases
     * via the alias map, unqualified references via the single-table
     * fallback, everything else dropped.
     *
     * @param  list<array{table: ?string, column: string}>  $refs
     * @param  array<string, string>  $aliases
     * @return array<string, list<string>>
     */
    private function resolve(array $refs, array $aliases, ?string $defaultTable): array
    {
        $result = [];

        foreach ($refs as $ref) {
            if ($ref['table'] !== null) {
                $table = $aliases[$ref['table']] ?? $ref['table'];
            } elseif ($defaultTable !== null) {
                $table = $defaultTable;
            } else {
                continue;
            }

            $result[$table] ??= [];

            if (! in_array($ref['column'], $result[$table], true)) {
                $result[$table][] = $ref['column'];
            }
        }

        return $result;
    }

    /**
     * Turn the parsed shape + EXPLAIN plan into index proposals.
     * Stops once MAX_SUGGESTIONS is reached and skips any table it has
     * already proposed an index for (even across multiple plan rows,
     * which happens for correlated subqueries).
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  array{aliases: array<string, string>, default_table: ?string, where: array<string, list<string>>, join: array<string, list<string>>, group: array<string, list<string>>, order: array<string, list<string>>}  $shape
     * @return list<array{table: string, reason: string, columns: list<string>, proposed_index: string, confidence: string}>
     */
    private function buildSuggestions(PDO $connection, string $database, array $plan, array $shape): array
    {
        $suggestions = [];
        $seen = [];

        foreach ($plan as $row) {
            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }

            $planTable = isset($row['table']) ? (string) $row['table'] : '';

            if ($planTable === '' || preg_match('/^[A-Za-z0-9_]+$/', $planTable) !== 1) {
                continue;
            }

            $realTable = $shape['aliases'][$planTable] ?? $planTable;

            if (isset($seen[$realTable])) {
                continue;
            }

            $type = strtolower((string) ($row['type'] ?? ''));
            $key = $row['key'] ?? null;
            $extra = (string) ($row['Extra'] ?? '');
            $estimatedRows = (int) ($row['rows'] ?? 0);

            $fullScan = $type === 'all';
            $keyMissing = $key === null || $key === '';
            $filesort = stripos($extra, 'Using filesort') !== false;
            $tempTable = stripos($extra, 'Using temporary') !== false;

            if (! $fullScan && ! $keyMissing && ! $filesort && ! $tempTable) {
                continue;
            }

            $whereCols = $shape['where'][$realTable] ?? [];
            $joinCols = $shape['join'][$realTable] ?? [];
            $sortCols = [];

            if ($filesort || $tempTable) {
                $sortCols = array_values(array_unique([
                    ...($shape['order'][$realTable] ?? []),
                    ...($shape['group'][$realTable] ?? []),
                ]));
            }

            $composed = $this->composeIndex($whereCols, $joinCols, $sortCols);

            if ($composed === []) {
                continue;
            }

            $validColumns = $this->filterExistingColumns($connection, $database, $realTable, $composed);

            if ($validColumns === []) {
                continue;
            }

            if ($this->isCoveredByExistingIndex($connection, $database, $realTable, $validColumns)) {
                continue;
            }

            $seen[$realTable] = true;
            $suggestions[] = [
                'table' => $realTable,
                'reason' => $this->reasonFor($fullScan, $keyMissing, $filesort, $tempTable, $estimatedRows),
                'columns' => $validColumns,
                'proposed_index' => $this->ddl($realTable, $validColumns),
                'confidence' => $this->confidenceFor($fullScan, $keyMissing, $filesort, $tempTable, $whereCols, $joinCols),
            ];
        }

        return $suggestions;
    }

    /**
     * Apply the leftmost-prefix rule: WHERE columns first (their
     * equality predicates benefit the most from being first),
     * then JOIN columns, then sort columns. Duplicates collapse and
     * the final list is capped at MAX_INDEX_COLUMNS because very wide
     * composite indexes pay more in write amplification than they
     * save in reads.
     *
     * @param  list<string>  $where
     * @param  list<string>  $join
     * @param  list<string>  $sort
     * @return list<string>
     */
    private function composeIndex(array $where, array $join, array $sort): array
    {
        $columns = [];

        foreach ([$where, $join, $sort] as $set) {
            foreach ($set as $column) {
                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        return array_slice($columns, 0, self::MAX_INDEX_COLUMNS);
    }

    /**
     * Drop proposed columns that do not actually exist on the table —
     * guards against aliases we misattributed or subquery artifacts.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function filterExistingColumns(PDO $connection, string $database, string $table, array $columns): array
    {
        try {
            $rows = $this->service->run($connection, <<<'SQL'
                SELECT COLUMN_NAME
                  FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name
            SQL, ['schema' => $database, 'name' => $table]);
        } catch (QueryExecutionException) {
            return [];
        }

        $actual = array_column($rows, 'COLUMN_NAME');

        return array_values(array_filter($columns, static fn (string $column): bool => in_array($column, $actual, true)));
    }

    /**
     * True when an existing index's leading columns already match the
     * proposal in order. The common case is "we re-invented the
     * primary key" or "we re-invented an existing index".
     *
     * @param  list<string>  $columns
     */
    private function isCoveredByExistingIndex(PDO $connection, string $database, string $table, array $columns): bool
    {
        try {
            $rows = $this->service->run($connection, <<<'SQL'
                SELECT INDEX_NAME, COLUMN_NAME
                  FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name
                 ORDER BY INDEX_NAME, SEQ_IN_INDEX
            SQL, ['schema' => $database, 'name' => $table]);
        } catch (QueryExecutionException) {
            return false;
        }

        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            $column = (string) ($row['COLUMN_NAME'] ?? '');

            if ($name === '' || $column === '') {
                continue;
            }

            $indexes[$name] ??= [];
            $indexes[$name][] = $column;
        }

        foreach ($indexes as $indexColumns) {
            if (array_slice($indexColumns, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $whereCols
     * @param  list<string>  $joinCols
     */
    private function confidenceFor(bool $fullScan, bool $keyMissing, bool $filesort, bool $tempTable, array $whereCols, array $joinCols): string
    {
        if ($fullScan && $whereCols !== []) {
            return 'high';
        }

        if ($keyMissing && ($whereCols !== [] || $joinCols !== [])) {
            return 'medium';
        }

        if ($filesort || $tempTable) {
            return 'medium';
        }

        return 'low';
    }

    private function reasonFor(bool $fullScan, bool $keyMissing, bool $filesort, bool $tempTable, int $estimatedRows): string
    {
        $parts = [];

        if ($fullScan) {
            $parts[] = "full table scan (~{$estimatedRows} rows)";
        } elseif ($keyMissing) {
            $parts[] = 'no index is used';
        }

        if ($filesort) {
            $parts[] = 'Using filesort';
        }

        if ($tempTable) {
            $parts[] = 'Using temporary';
        }

        return $parts === [] ? 'execution plan shows room for an index' : implode('; ', $parts);
    }

    /**
     * @param  list<string>  $columns
     */
    private function ddl(string $table, array $columns): string
    {
        $indexName = 'idx_'.$table.'_'.implode('_', $columns);
        $columnList = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));

        return "CREATE INDEX `{$indexName}`\nON `{$table}` ({$columnList})\n-- DO NOT APPLY WITHOUT REVIEW";
    }

    private function isJoinKeyword(string $token): bool
    {
        return in_array(strtoupper($token), self::JOIN_KEYWORDS, true);
    }

    /**
     * Split a clause body on top-level commas, ignoring commas that
     * appear inside parentheses (function calls, tuple expressions).
     *
     * @return list<string>
     */
    private function splitTopLevel(string $body): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '(') {
                $depth++;
                $buffer .= $char;
            } elseif ($char === ')') {
                $depth--;
                $buffer .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }

        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }
}
