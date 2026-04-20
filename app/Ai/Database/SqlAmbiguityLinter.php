<?php

namespace App\Ai\Database;

/**
 * Lints a SELECT for correctness pitfalls that SqlSafetyValidator
 * intentionally ignores — specifically, unqualified identifiers in
 * GROUP BY when the query joins multiple tables. On a schema where
 * two joined tables share a column name, `GROUP BY name` silently
 * resolves to one of the real columns rather than the SELECT alias,
 * producing one row per that column's value instead of per group.
 * That is a wrong-result bug, not a safety bug, so it is flagged
 * here as a separate preflight step tools can call after the safety
 * verdict comes back allowed.
 *
 * Single-table queries are never flagged — bare identifiers are
 * unambiguous there. Qualified references (`table.column`),
 * expressions (`CONCAT(...)`, `COUNT(*)`), and numeric positions are
 * never flagged either. The linter is intentionally conservative: a
 * bare identifier that happens to be unambiguous still gets rewritten
 * to the qualified form, which is strictly more readable — the cost
 * of a false positive is lower than the cost of silently wrong data.
 */
class SqlAmbiguityLinter
{
    /**
     * @return string|null Actionable rewrite hint, or null if the query is clean.
     */
    public function lint(string $query): ?string
    {
        $normalized = $this->normalize($query);

        if (! $this->hasJoin($normalized)) {
            return null;
        }

        $bare = $this->bareIdentifiersInGroupBy($normalized);

        if ($bare === []) {
            return null;
        }

        $quoted = '`'.implode('`, `', $bare).'`';
        $first = $bare[0];

        return "Unqualified identifier(s) {$quoted} in GROUP BY of a multi-table query are ambiguous. When any joined table has a column with the same name, MySQL silently resolves to the column (not the SELECT alias) and returns one row per value of that column instead of per group — producing duplicate-looking output. Fix by either (a) qualifying each identifier with its table alias, e.g. `u.{$first}`, (b) grouping by the underlying expression, e.g. `CONCAT(u.first_name, ' ', u.last_name)`, or (c) renaming any SELECT alias so it cannot collide with a real column (`user_name`, not `name`).";
    }

    /**
     * Strip comments and string/backtick literals so identifiers inside
     * data or comments cannot produce false positives.
     */
    private function normalize(string $query): string
    {
        $query = preg_replace('/\/\*.*?\*\//s', ' ', $query) ?? $query;
        $query = preg_replace('/--[^\n\r]*/', ' ', $query) ?? $query;
        $query = preg_replace('/#[^\n\r]*/', ' ', $query) ?? $query;

        $query = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $query) ?? $query;
        $query = preg_replace('/"(?:[^"\\\\]|\\\\.|"")*"/s', '""', $query) ?? $query;
        $query = preg_replace('/`(?:[^`\\\\]|\\\\.|``)*`/s', '``', $query) ?? $query;

        return $query;
    }

    /**
     * True when the query is multi-table: an explicit JOIN keyword, or
     * a comma-separated FROM list (the old-style implicit cross join).
     */
    private function hasJoin(string $query): bool
    {
        if (preg_match('/\bJOIN\b/i', $query) === 1) {
            return true;
        }

        return preg_match(
            '/\bFROM\s+[A-Za-z_][A-Za-z0-9_]*(?:\s+(?:AS\s+)?[A-Za-z_][A-Za-z0-9_]*)?\s*,/i',
            $query,
        ) === 1;
    }

    /**
     * Return every bare (unqualified, non-expression, non-positional)
     * identifier found in GROUP BY clauses of the query.
     *
     * @return list<string>
     */
    private function bareIdentifiersInGroupBy(string $query): array
    {
        $pattern = '/\bGROUP\s+BY\b(.*?)(?=\b(?:HAVING|ORDER\s+BY|LIMIT|OFFSET|UNION|INTERSECT|EXCEPT|INTO|FOR|LOCK)\b|$)/is';

        if (preg_match_all($pattern, $query, $matches) === false || $matches[1] === []) {
            return [];
        }

        $bare = [];

        foreach ($matches[1] as $body) {
            foreach ($this->splitTopLevel((string) $body) as $item) {
                $identifier = $this->bareIdentifier($item);

                if ($identifier !== null && ! in_array($identifier, $bare, true)) {
                    $bare[] = $identifier;
                }
            }
        }

        return $bare;
    }

    /**
     * Return a bare identifier (no dot, no function call, no numeric
     * ordinal, no keyword modifier) — or null if the item is anything
     * else and therefore safe.
     */
    private function bareIdentifier(string $item): ?string
    {
        $trimmed = trim($item);
        $trimmed = preg_replace('/\s+(?:ASC|DESC)\s*$/i', '', $trimmed) ?? $trimmed;
        $trimmed = trim($trimmed);

        if ($trimmed === '') {
            return null;
        }

        if (str_contains($trimmed, '.') || str_contains($trimmed, '(')) {
            return null;
        }

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            return null;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Split on top-level commas, ignoring commas inside parentheses.
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
