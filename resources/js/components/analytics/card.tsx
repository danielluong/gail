import type { ReactNode } from 'react';

export function Card({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-surface-250 dark:bg-surface-50">
            {children}
        </div>
    );
}

export function EmptyState({ message }: { message: string }) {
    return (
        <p className="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
            {message}
        </p>
    );
}
