import { formatNumber } from '@/lib/numbers';

export function DayBar({
    label,
    value,
    max,
    tooltip,
}: {
    label: string;
    value: number;
    max: number;
    tooltip: string;
}) {
    const heightPct = (value / max) * 100;

    return (
        <div
            className="group relative flex flex-1 flex-col justify-end"
            title={tooltip}
        >
            <div
                className="w-full rounded-t bg-blue-500 transition-colors group-hover:bg-blue-400"
                style={{
                    height: `${heightPct}%`,
                    minHeight: value > 0 ? 2 : 0,
                }}
            />
            <span className="pointer-events-none absolute -top-8 left-1/2 z-10 hidden -translate-x-1/2 rounded bg-gray-900 px-2 py-1 text-xs whitespace-nowrap text-white group-hover:block dark:bg-gray-700">
                {label}: {formatNumber(value)}
            </span>
        </div>
    );
}

export function LegendSwatch({
    className,
    label,
}: {
    className: string;
    label: string;
}) {
    return (
        <span className="flex items-center gap-1.5">
            <span className={`h-2 w-3 rounded-sm ${className}`} />
            {label}
        </span>
    );
}
