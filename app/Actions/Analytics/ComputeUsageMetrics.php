<?php

namespace App\Actions\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ComputeUsageMetrics
{
    private const CACHE_TTL_SECONDS = 300;

    private const TABLE = 'agent_conversation_messages';

    public function __construct(
        private readonly AnalyticsJsonSqlDialect $sql = new AnalyticsJsonSqlDialect,
    ) {}

    /**
     * Build the analytics dashboard payload: totals, per-day buckets,
     * tool usage histogram, and per-model breakdown, for the given
     * number of trailing days.
     *
     * Results are cached for a short window so the dashboard can be
     * refreshed repeatedly without rescanning every message row.
     *
     * JSON extraction uses driver-specific SQL via private helpers so
     * the same code runs on both SQLite (JSON1) and PostgreSQL (jsonb).
     *
     * @return array{
     *     range_days: int,
     *     totals: array{messages: int, user_messages: int, assistant_messages: int, total_tokens: int, prompt_tokens: int, completion_tokens: int, tool_calls: int},
     *     messages_per_day: array<int, array{date: string, count: int}>,
     *     tokens_per_day: array<int, array{date: string, prompt: int, completion: int}>,
     *     tool_usage: array<int, array{name: string, count: int}>,
     *     model_breakdown: array<int, array{model: string, provider: ?string, messages: int, tokens: int}>,
     * }
     */
    public function execute(int $days = 30): array
    {
        return Cache::remember(
            "gail:usage-metrics:{$days}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->compute($days),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(int $days): array
    {
        $since = Carbon::now()->subDays($days - 1)->startOfDay();

        return [
            'range_days' => $days,
            'totals' => $this->totals($since),
            'messages_per_day' => $this->messagesPerDay($since, $days),
            'tokens_per_day' => $this->tokensPerDay($since, $days),
            'tool_usage' => $this->toolUsage($since),
            'model_breakdown' => $this->modelBreakdown($since),
        ];
    }

    /**
     * @return array{messages: int, user_messages: int, assistant_messages: int, total_tokens: int, prompt_tokens: int, completion_tokens: int, tool_calls: int}
     */
    private function totals(Carbon $since): array
    {
        $promptExpr = $this->sql->jsonIntValue('usage', 'prompt_tokens');
        $completionExpr = $this->sql->jsonIntValue('usage', 'completion_tokens');
        $validUsage = $this->sql->jsonValid('usage');

        $row = DB::table(self::TABLE)
            ->where('created_at', '>=', $since)
            ->selectRaw("
                COUNT(*) AS messages,
                SUM(CASE WHEN role = ? THEN 1 ELSE 0 END) AS user_messages,
                SUM(CASE WHEN role = ? THEN 1 ELSE 0 END) AS assistant_messages,
                COALESCE(SUM(
                    CASE WHEN {$validUsage}
                        THEN COALESCE({$promptExpr}, 0)
                        ELSE 0
                    END
                ), 0) AS prompt_tokens,
                COALESCE(SUM(
                    CASE WHEN {$validUsage}
                        THEN COALESCE({$completionExpr}, 0)
                        ELSE 0
                    END
                ), 0) AS completion_tokens
            ", ['user', 'assistant'])
            ->first();

        $toolCalls = $this->countToolCalls($since);

        $prompt = (int) ($row->prompt_tokens ?? 0);
        $completion = (int) ($row->completion_tokens ?? 0);

        return [
            'messages' => (int) ($row->messages ?? 0),
            'user_messages' => (int) ($row->user_messages ?? 0),
            'assistant_messages' => (int) ($row->assistant_messages ?? 0),
            'total_tokens' => $prompt + $completion,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * Count total tool calls by iterating in PHP. This avoids the
     * SQLite-specific json_each lateral join and works on any driver.
     */
    private function countToolCalls(Carbon $since): int
    {
        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $since)
            ->whereNotNull('tool_calls')
            ->where('tool_calls', '!=', '[]')
            ->pluck('tool_calls');

        $count = 0;

        foreach ($rows as $raw) {
            $calls = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            if (is_array($calls)) {
                $count += count($calls);
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{date: string, count: int}>
     */
    private function messagesPerDay(Carbon $since, int $days): array
    {
        $dateExpr = $this->sql->dateExpression('created_at');

        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $since)
            ->selectRaw("{$dateExpr} AS day, COUNT(*) AS count")
            ->groupBy('day')
            ->pluck('count', 'day')
            ->map(fn ($count) => (int) $count)
            ->all();

        $buckets = $this->emptyDayBuckets($since, $days);

        foreach ($rows as $day => $count) {
            if (isset($buckets[$day])) {
                $buckets[$day] = $count;
            }
        }

        $list = [];
        foreach ($buckets as $date => $count) {
            $list[] = ['date' => $date, 'count' => $count];
        }

        return $list;
    }

    /**
     * @return array<int, array{date: string, prompt: int, completion: int}>
     */
    private function tokensPerDay(Carbon $since, int $days): array
    {
        $dateExpr = $this->sql->dateExpression('created_at');
        $promptExpr = $this->sql->jsonIntValue('usage', 'prompt_tokens');
        $completionExpr = $this->sql->jsonIntValue('usage', 'completion_tokens');
        $validUsage = $this->sql->jsonValid('usage');

        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $since)
            ->selectRaw("
                {$dateExpr} AS day,
                COALESCE(SUM(
                    CASE WHEN {$validUsage}
                        THEN COALESCE({$promptExpr}, 0)
                        ELSE 0
                    END
                ), 0) AS prompt,
                COALESCE(SUM(
                    CASE WHEN {$validUsage}
                        THEN COALESCE({$completionExpr}, 0)
                        ELSE 0
                    END
                ), 0) AS completion
            ")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $prompt = $this->emptyDayBuckets($since, $days);
        $completion = $this->emptyDayBuckets($since, $days);

        foreach ($rows as $day => $row) {
            if (isset($prompt[$day])) {
                $prompt[$day] = (int) $row->prompt;
                $completion[$day] = (int) $row->completion;
            }
        }

        $result = [];
        foreach ($prompt as $date => $promptCount) {
            $result[] = [
                'date' => $date,
                'prompt' => $promptCount,
                'completion' => $completion[$date],
            ];
        }

        return $result;
    }

    /**
     * Aggregate tool calls by tool name using PHP-side iteration,
     * avoiding the SQLite-specific json_each lateral join.
     *
     * @return array<int, array{name: string, count: int}>
     */
    private function toolUsage(Carbon $since): array
    {
        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $since)
            ->whereNotNull('tool_calls')
            ->where('tool_calls', '!=', '[]')
            ->pluck('tool_calls');

        $counts = [];

        foreach ($rows as $raw) {
            $calls = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            if (! is_array($calls)) {
                continue;
            }

            foreach ($calls as $call) {
                $name = $call['name'] ?? null;
                if ($name !== null) {
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        return array_map(
            fn ($name, $count) => ['name' => (string) $name, 'count' => $count],
            array_keys($counts),
            array_values($counts),
        );
    }

    /**
     * @return array<int, array{model: string, provider: ?string, messages: int, tokens: int}>
     */
    private function modelBreakdown(Carbon $since): array
    {
        $modelExpr = $this->sql->jsonExtract('meta', 'model');
        $providerExpr = $this->sql->jsonExtract('meta', 'provider');
        $promptExpr = $this->sql->jsonIntValue('usage', 'prompt_tokens');
        $completionExpr = $this->sql->jsonIntValue('usage', 'completion_tokens');
        $validMeta = $this->sql->jsonValid('meta');
        $validUsage = $this->sql->jsonValid('usage');

        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $since)
            ->where('role', 'assistant')
            ->whereRaw($validMeta)
            ->whereRaw("{$modelExpr} IS NOT NULL")
            ->selectRaw("
                {$modelExpr} AS model,
                {$providerExpr} AS provider,
                COUNT(*) AS messages,
                COALESCE(SUM(
                    CASE WHEN {$validUsage}
                        THEN COALESCE({$promptExpr}, 0) +
                             COALESCE({$completionExpr}, 0)
                        ELSE 0
                    END
                ), 0) AS tokens
            ")
            ->groupBy('model', 'provider')
            ->orderByDesc('messages')
            ->get();

        return $rows
            ->map(fn ($row) => [
                'model' => (string) $row->model,
                'provider' => $row->provider !== null ? (string) $row->provider : null,
                'messages' => (int) $row->messages,
                'tokens' => (int) $row->tokens,
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function emptyDayBuckets(Carbon $since, int $days): array
    {
        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $buckets[$since->copy()->addDays($i)->toDateString()] = 0;
        }

        return $buckets;
    }
}
