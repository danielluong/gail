export function StatCard({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-surface-250 dark:bg-surface-50">
            <p className="text-xs font-semibold tracking-wider text-gray-500 uppercase">
                {label}
            </p>
            <p className="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                {value}
            </p>
            {hint && (
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {hint}
                </p>
            )}
        </div>
    );
}
