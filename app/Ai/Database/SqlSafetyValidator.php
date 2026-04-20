<?php

namespace App\Ai\Database;

/**
 * Enforces read-only SQL at the agent layer, independent of the
 * database user's real permissions. Rejects any statement that could
 * mutate data, schema, privileges, or server state, plus multi-statement
 * payloads and obfuscation techniques (comments, versioned `/*!*\/`
 * blocks, `SELECT ... INTO OUTFILE`).
 *
 * The validator is dialect-agnostic on purpose so it can be reused by a
 * future PostgreSQLDatabaseAgent without forking safety logic. Anything
 * MySQL-specific (INFORMATION_SCHEMA queries, EXPLAIN format) lives in
 * the per-engine tools.
 */
class SqlSafetyValidator
{
    /**
     * Statements the agent is allowed to run. Every other leading
     * keyword is rejected, including WITH/CTE — because a CTE can front
     * an INSERT/UPDATE/DELETE on MySQL 8+.
     *
     * @var list<string>
     */
    private const ALLOWED_STARTS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    /**
     * Keywords that must not appear anywhere in the analyzable query
     * (after comments and string literals are stripped). Checked with
     * word boundaries so table/column names containing these substrings
     * are safe.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE',
        'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'RENAME',
        'GRANT', 'REVOKE',
        'LOCK', 'UNLOCK', 'HANDLER',
        'LOAD', 'CALL', 'DO', 'SET', 'USE',
        'RESET', 'FLUSH', 'KILL', 'OPTIMIZE', 'REPAIR', 'ANALYZE',
        'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'START',
    ];

    public function validate(string $query): SqlValidationResult
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            return SqlValidationResult::deny('Empty query.');
        }

        $withoutComments = $this->stripComments($trimmed);

        if (str_contains($trimmed, '/*!')) {
            return SqlValidationResult::deny('Versioned MySQL comments (/*! ... */) are not allowed — they can hide executable statements.');
        }

        $analyzable = $this->stripStringLiterals($withoutComments);
        $analyzable = trim($analyzable);

        if ($analyzable === '') {
            return SqlValidationResult::deny('Query contains no executable SQL once comments and string literals are removed.');
        }

        if ($this->hasMultipleStatements($analyzable)) {
            return SqlValidationResult::deny('Multiple statements are not allowed — submit one query at a time.');
        }

        $firstKeyword = $this->firstKeyword($analyzable);

        if ($firstKeyword === null || ! in_array($firstKeyword, self::ALLOWED_STARTS, true)) {
            $allowed = implode(', ', self::ALLOWED_STARTS);

            return SqlValidationResult::deny("Only read-only statements are allowed ({$allowed}). Got: ".($firstKeyword ?? 'unknown').'.');
        }

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.$keyword.'\b/i', $analyzable) === 1) {
                return SqlValidationResult::deny("Forbidden keyword '{$keyword}' detected — this agent only runs read-only queries.");
            }
        }

        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $analyzable) === 1) {
            return SqlValidationResult::deny('SELECT ... INTO OUTFILE/DUMPFILE writes to the filesystem and is not allowed.');
        }

        if (preg_match('/\bFOR\s+UPDATE\b/i', $analyzable) === 1) {
            return SqlValidationResult::deny('SELECT ... FOR UPDATE acquires write locks and is not allowed.');
        }

        return SqlValidationResult::allow($firstKeyword);
    }

    /**
     * Remove `--` and `#` line comments and `/* ... *\/` block comments.
     * Preserves line structure with spaces so error positions stay close
     * to the original when the DB reports one.
     */
    private function stripComments(string $query): string
    {
        $query = preg_replace('/\/\*.*?\*\//s', ' ', $query) ?? $query;
        $query = preg_replace('/(^|\s)--[^\n\r]*/', '$1 ', $query) ?? $query;
        $query = preg_replace('/#[^\n\r]*/', ' ', $query) ?? $query;

        return $query;
    }

    /**
     * Replace every quoted string literal with a neutral placeholder so
     * keywords embedded in data (e.g. `WHERE name = 'drop table'`) don't
     * trip the forbidden-keyword check. Handles single, double, and
     * backtick quoting with both escaped-quote and doubled-quote forms.
     */
    private function stripStringLiterals(string $query): string
    {
        return preg_replace(
            [
                "/'(?:[^'\\\\]|\\\\.|'')*'/s",
                '/"(?:[^"\\\\]|\\\\.|"")*"/s',
                '/`(?:[^`\\\\]|\\\\.|``)*`/s',
            ],
            ["''", '""', '``'],
            $query,
        ) ?? $query;
    }

    private function hasMultipleStatements(string $analyzable): bool
    {
        $withoutTrailing = rtrim($analyzable, "; \t\n\r\0\x0B");

        return str_contains($withoutTrailing, ';');
    }

    private function firstKeyword(string $analyzable): ?string
    {
        if (preg_match('/^\s*\(*\s*([A-Za-z]+)/', $analyzable, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }
}
