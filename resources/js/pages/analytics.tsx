import { Head, Link } from '@inertiajs/react';
import { Card, EmptyState } from '@/components/analytics/card';
import { DayBar, LegendSwatch } from '@/components/analytics/day-bar';
import { StatCard } from '@/components/analytics/stat-card';
import { ErrorBoundary } from '@/components/error-boundary';
import { formatDayLabel } from '@/lib/dates';
import { formatNumber } from '@/lib/numbers';

type DayCount = { date: string; count: number };
type DayTokens = { date: string; prompt: number; completion: number };
type ToolRow = { name: string; count: number };
type ModelRow = {
    model: string;
    provider: string | null;
    messages: number;
    tokens: number;
};

type Totals = {
    messages: number;
    user_messages: number;
    assistant_messages: number;
    total_tokens: number;
    prompt_tokens: number;
    completion_tokens: number;
    tool_calls: number;
};

type Props = {
    range_days: number;
    totals: Totals;
    messages_per_day: DayCount[];
    tokens_per_day: DayTokens[];
    tool_usage: ToolRow[];
    model_breakdown: ModelRow[];
};

export default function Analytics(props: Props) {
    return (
        <ErrorBoundary>
            <AnalyticsPage {...props} />
        </ErrorBoundary>
    );
}

function AnalyticsPage({
    range_days,
    totals,
    messages_per_day,
    tokens_per_day,
    tool_usage,
    model_breakdown,
}: Props) {
    const maxMessages = Math.max(1, ...messages_per_day.map((d) => d.count));
    const maxTokens = Math.max(
        1,
        ...tokens_per_day.map((d) => d.prompt + d.completion),
    );
    const maxToolCount = Math.max(1, ...tool_usage.map((t) => t.count));
    const maxModelMessages = Math.max(
        1,
        ...model_breakdown.map((m) => m.messages),
    );

    return (
        <>
            <Head title="Analytics" />

            <div className="min-h-screen bg-white dark:bg-surface-150">
                <div className="mx-auto max-w-6xl px-6 py-8">
                    <header className="mb-8 flex items-center justify-between">
                        <div>
                            <h1 className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                                Usage Analytics
                            </h1>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Last {range_days} days
                            </p>
                        </div>
                        <Link
                            href="/"
                            className="rounded-lg border border-gray-200 px-3 py-1.5 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:border-surface-250 dark:text-gray-200 dark:hover:bg-surface-250"
                        >
                            Back to chat
                        </Link>
                    </header>

                    <section className="mb-8 grid grid-cols-2 gap-4 md:grid-cols-4">
                        <StatCard
                            label="Messages"
                            value={formatNumber(totals.messages)}
                            hint={`${formatNumber(totals.user_messages)} user · ${formatNumber(totals.assistant_messages)} assistant`}
                        />
                        <StatCard
                            label="Total tokens"
                            value={formatNumber(totals.total_tokens)}
                            hint={`${formatNumber(totals.prompt_tokens)} in · ${formatNumber(totals.completion_tokens)} out`}
                        />
                        <StatCard
                            label="Tool calls"
                            value={formatNumber(totals.tool_calls)}
                            hint={`${tool_usage.length} distinct tools`}
                        />
                        <StatCard
                            label="Models used"
                            value={formatNumber(model_breakdown.length)}
                            hint={model_breakdown[0]?.model ?? '—'}
                        />
                    </section>

                    <section className="mb-8">
                        <h2 className="mb-3 text-sm font-semibold tracking-wider text-gray-500 uppercase">
                            Messages per day
                        </h2>
                        <Card>
                            {messages_per_day.length === 0 ? (
                                <EmptyState message="No messages in this range." />
                            ) : (
                                <div className="flex h-40 items-stretch gap-1">
                                    {messages_per_day.map((day) => (
                                        <DayBar
                                            key={day.date}
                                            label={formatDayLabel(day.date)}
                                            value={day.count}
                                            max={maxMessages}
                                            tooltip={`${day.date}: ${formatNumber(day.count)} messages`}
                                        />
                                    ))}
                                </div>
                            )}
                        </Card>
                    </section>

                    <section className="mb-8">
                        <h2 className="mb-3 text-sm font-semibold tracking-wider text-gray-500 uppercase">
                            Tokens per day
                        </h2>
                        <Card>
                            {tokens_per_day.length === 0 ? (
                                <EmptyState message="No token usage in this range." />
                            ) : (
                                <>
                                    <div className="mb-2 flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                                        <LegendSwatch
                                            className="bg-blue-500"
                                            label="Prompt"
                                        />
                                        <LegendSwatch
                                            className="bg-emerald-500"
                                            label="Completion"
                                        />
                                    </div>
                                    <div className="flex h-40 items-stretch gap-1">
                                        {tokens_per_day.map((day) => {
                                            const total =
                                                day.prompt + day.completion;
                                            const heightPct =
                                                (total / maxTokens) * 100;
                                            const promptPct =
                                                total > 0
                                                    ? (day.prompt / total) * 100
                                                    : 0;

                                            return (
                                                <div
                                                    key={day.date}
                                                    className="group relative flex flex-1 flex-col justify-end"
                                                >
                                                    <div
                                                        className="flex w-full flex-col overflow-hidden rounded-t"
                                                        style={{
                                                            height: `${heightPct}%`,
                                                            minHeight:
                                                                total > 0
                                                                    ? 2
                                                                    : 0,
                                                        }}
                                                    >
                                                        <div
                                                            className="bg-blue-500"
                                                            style={{
                                                                height: `${promptPct}%`,
                                                            }}
                                                        />
                                                        <div
                                                            className="bg-emerald-500"
                                                            style={{
                                                                height: `${100 - promptPct}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="pointer-events-none absolute -top-8 left-1/2 z-10 hidden -translate-x-1/2 rounded bg-gray-900 px-2 py-1 text-xs whitespace-nowrap text-white group-hover:block dark:bg-gray-700">
                                                        {day.date}:{' '}
                                                        {formatNumber(total)}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </Card>
                    </section>

                    <div className="grid gap-6 md:grid-cols-2">
                        <section>
                            <h2 className="mb-3 text-sm font-semibold tracking-wider text-gray-500 uppercase">
                                Most used tools
                            </h2>
                            <Card>
                                {tool_usage.length === 0 ? (
                                    <EmptyState message="No tool calls in this range." />
                                ) : (
                                    <ul className="space-y-3">
                                        {tool_usage.map((tool) => (
                                            <li key={tool.name}>
                                                <div className="mb-1 flex items-center justify-between text-sm">
                                                    <span className="font-mono text-gray-800 dark:text-gray-200">
                                                        {tool.name}
                                                    </span>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        {formatNumber(
                                                            tool.count,
                                                        )}
                                                    </span>
                                                </div>
                                                <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-surface-250">
                                                    <div
                                                        className="h-full bg-indigo-500"
                                                        style={{
                                                            width: `${(tool.count / maxToolCount) * 100}%`,
                                                        }}
                                                    />
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card>
                        </section>

                        <section>
                            <h2 className="mb-3 text-sm font-semibold tracking-wider text-gray-500 uppercase">
                                Model breakdown
                            </h2>
                            <Card>
                                {model_breakdown.length === 0 ? (
                                    <EmptyState message="No assistant messages in this range." />
                                ) : (
                                    <ul className="space-y-3">
                                        {model_breakdown.map((row) => (
                                            <li key={row.model}>
                                                <div className="mb-1 flex items-center justify-between text-sm">
                                                    <span className="font-mono text-gray-800 dark:text-gray-200">
                                                        {row.model}
                                                    </span>
                                                    <span className="text-gray-500 dark:text-gray-400">
                                                        {formatNumber(
                                                            row.messages,
                                                        )}{' '}
                                                        msg ·{' '}
                                                        {formatNumber(
                                                            row.tokens,
                                                        )}{' '}
                                                        tok
                                                    </span>
                                                </div>
                                                <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-surface-250">
                                                    <div
                                                        className="h-full bg-purple-500"
                                                        style={{
                                                            width: `${(row.messages / maxModelMessages) * 100}%`,
                                                        }}
                                                    />
                                                </div>
                                                {row.provider && (
                                                    <p className="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                                        {row.provider}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Card>
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}
