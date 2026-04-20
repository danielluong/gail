<?php

namespace App\Actions\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Cross-driver SQL dialect helpers for analytics queries. Isolates the
 * SQLite/PostgreSQL branching from ComputeUsageMetrics so the aggregation
 * logic reads as pure intent.
 */
class AnalyticsJsonSqlDialect
{
    public function __construct(
        private readonly ?string $driver = null,
    ) {}

    private function driver(): string
    {
        return $this->driver ?? DB::getDriverName();
    }

    /**
     * Extract a text value from a JSON column.
     */
    public function jsonExtract(string $column, string $key): string
    {
        return match ($this->driver()) {
            'pgsql' => "{$column}::jsonb->>'{$key}'",
            default => "json_extract({$column}, \"$.{$key}\")",
        };
    }

    /**
     * Extract an integer value from a JSON column.
     */
    public function jsonIntValue(string $column, string $key): string
    {
        return match ($this->driver()) {
            'pgsql' => "({$column}::jsonb->>'{$key}')::integer",
            default => "CAST(json_extract({$column}, \"$.{$key}\") AS INTEGER)",
        };
    }

    /**
     * Check that a JSON column contains parseable content.
     */
    public function jsonValid(string $column): string
    {
        return match ($this->driver()) {
            'pgsql' => "{$column} IS NOT NULL AND {$column}::text != ''",
            default => "json_valid({$column})",
        };
    }

    /**
     * Extract the date portion of a timestamp column.
     */
    public function dateExpression(string $column): string
    {
        return match ($this->driver()) {
            'pgsql' => "{$column}::date",
            default => "DATE({$column})",
        };
    }
}
