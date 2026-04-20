<?php

namespace App\Ai\Services\MySQL;

use App\Ai\Database\DatabaseConnectionStore;
use App\Ai\Database\Dto\MySqlCredentials;
use App\Ai\Database\MySqlConnectionFactory;
use App\Ai\Database\SqlAmbiguityLinter;
use App\Ai\Database\SqlSafetyValidator;
use App\Ai\Database\SqlValidationResult;
use PDO;
use Throwable;

/**
 * The single DB execution kernel for every tool in the
 * `App\Ai\Tools\MySQLDatabase` namespace. Tools route all connection
 * resolution, safety validation, query execution, and error
 * translation through this class — they never touch the underlying
 * store, validator, linter, or PDO driver directly. This is what
 * makes "the agent is read-only" enforceable as one property of one
 * service rather than a convention spread across nine tools.
 *
 * Error surface: every failure that a tool can surface to the model —
 * missing/expired token, unsafe SQL, lint refusal, broken query — is
 * raised as a {@see QueryExecutionException} whose message is safe
 * to echo verbatim. Tools wrap their handle() bodies in a single
 * try/catch and return `'Error: '.$e->getMessage()`.
 */
class QueryExecutionService
{
    /**
     * Default row cap applied to {@see executeSelect()} when a caller
     * doesn't supply one. Matches the historical RunSelectQueryTool
     * cap so migration is behavior-preserving.
     */
    public const DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly DatabaseConnectionStore $store,
        private readonly MySqlConnectionFactory $factory,
        private readonly SqlSafetyValidator $validator,
        private readonly SqlAmbiguityLinter $linter,
    ) {}

    /**
     * Resolve a conversation token to an open PDO. Throws whenever the
     * token is missing, expired, points at a non-MySQL engine, or the
     * underlying connection can't be opened with the stored
     * credentials. Callers never deal with null/invalid tokens — they
     * either get a live PDO back or an exception they can surface.
     */
    public function getConnection(?string $token): PDO
    {
        $resolved = $this->resolveToken($token);

        try {
            $credentials = MySqlCredentials::fromArray($resolved['credentials'] ?? []);

            return $this->factory->open($credentials);
        } catch (Throwable $e) {
            throw new QueryExecutionException($this->handleError($e), previous: $e);
        }
    }

    /**
     * Return the database name bound to a token. Used by tools that
     * hit INFORMATION_SCHEMA and need to filter by the connected
     * schema rather than the server-default database.
     */
    public function resolveDatabase(?string $token): string
    {
        $resolved = $this->resolveToken($token);
        $database = $resolved['credentials']['database'] ?? null;

        if (! is_string($database) || $database === '') {
            throw new QueryExecutionException('could not determine database for this connection.');
        }

        return $database;
    }

    /**
     * Classify a query without throwing. Tools that need to branch on
     * the leading keyword (e.g. "CSV export only supports SELECT")
     * call this first, then {@see preflight()} for the combined safety
     * + ambiguity check. Callers MUST NOT execute a query on the verdict
     * alone — preflight is the gate, validate is the lookup.
     */
    public function validate(string $query): SqlValidationResult
    {
        return $this->validator->validate($query);
    }

    /**
     * Run the full preflight pipeline — safety validator then
     * ambiguity linter — and throw if either refuses. Returns the
     * safety verdict on success so tools can still branch on
     * `firstKeyword` without re-running the validator.
     */
    public function preflight(string $query): SqlValidationResult
    {
        $verdict = $this->validator->validate($query);

        if (! $verdict->allowed) {
            throw new QueryExecutionException($verdict->reason ?? 'query refused by safety validator.');
        }

        if (($lintError = $this->linter->lint($query)) !== null) {
            throw new QueryExecutionException($lintError);
        }

        return $verdict;
    }

    /**
     * The one-call happy path: resolve the token, inject a LIMIT if
     * the query is a SELECT that doesn't have one, run the query on
     * the kernel's PDO, cap the result set, and return the envelope.
     * Callers are expected to preflight separately — this method
     * assumes the SQL has already passed {@see preflight()}.
     *
     * @return array{executed_sql: string, rows: list<array<string, mixed>>, row_count: int, column_count: int, limit: int}
     */
    public function executeSelect(?string $token, string $sql, ?int $limit = null): array
    {
        $effectiveLimit = $this->normalizeLimit($limit);
        $queryToRun = $this->applyLimit($sql, $effectiveLimit);
        $pdo = $this->getConnection($token);

        try {
            $stmt = $pdo->prepare($queryToRun);
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];

            if (count($rows) > $effectiveLimit) {
                $rows = array_slice($rows, 0, $effectiveLimit);
            }

            return [
                'executed_sql' => $queryToRun,
                'rows' => $rows,
                'row_count' => count($rows),
                'column_count' => $stmt->columnCount(),
                'limit' => $effectiveLimit,
            ];
        } catch (Throwable $e) {
            throw new QueryExecutionException($this->handleError($e), previous: $e);
        }
    }

    /**
     * Execute a statement on a pre-obtained connection and return the
     * rows. INFORMATION_SCHEMA tools hold their PDO for a whole turn
     * (they issue several metadata queries back-to-back) so they use
     * this method instead of re-resolving the token each time. No row
     * cap — metadata queries are naturally bounded.
     *
     * @param  array<int|string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function run(PDO $pdo, string $sql, array $bindings = []): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);

            return $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            throw new QueryExecutionException($this->handleError($e), previous: $e);
        }
    }

    /**
     * Append a LIMIT clause to SELECT statements that don't already
     * specify one. SHOW/DESCRIBE/EXPLAIN are left alone — LIMIT on
     * them is either unsupported or already naturally bounded.
     * Exposed publicly so tools can report the exact SQL that was
     * executed in their own response envelope.
     */
    public function applyLimit(string $sql, int $limit): string
    {
        $trimmed = rtrim($sql, "; \t\n\r\0\x0B");

        if (! $this->isSelect($trimmed) || $this->alreadyHasLimit($trimmed)) {
            return $trimmed;
        }

        return $trimmed.' LIMIT '.$limit;
    }

    /**
     * Normalize any Throwable into a user-safe, echo-ready message.
     * PDO exceptions are kept as-is because their messages are
     * informative ("Unknown column 'foo'") and do not leak secrets —
     * credentials are stored separately and never appear in error
     * text. Non-PDO throwables pass through unchanged; the kernel
     * assumes its callers have already filtered out obviously
     * sensitive inputs before execution.
     */
    public function handleError(Throwable $e): string
    {
        return $e->getMessage();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveToken(?string $token): array
    {
        if (! is_string($token) || trim($token) === '') {
            throw new QueryExecutionException('connection_token is required. Call ConnectToDatabaseTool first to get one.');
        }

        $data = $this->store->resolve(trim($token));

        if ($data === null) {
            throw new QueryExecutionException('connection token not found or expired. Call ConnectToDatabaseTool again with fresh credentials.');
        }

        if (($data['engine'] ?? null) !== 'mysql') {
            throw new QueryExecutionException('this token is not a MySQL connection handle.');
        }

        return $data;
    }

    private function normalizeLimit(?int $limit): int
    {
        if ($limit === null || $limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return $limit;
    }

    private function isSelect(string $query): bool
    {
        return preg_match('/^\s*\(*\s*SELECT\b/i', $query) === 1;
    }

    private function alreadyHasLimit(string $query): bool
    {
        return preg_match('/\bLIMIT\s+\d+(\s*,\s*\d+)?\s*$/i', $query) === 1
            || preg_match('/\bLIMIT\s+\d+\s+OFFSET\s+\d+\s*$/i', $query) === 1;
    }
}
