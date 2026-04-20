import { memo } from 'react';

type Props = {
    messageId: string | number;
    index: number;
    total: number;
    onSelect: (messageId: string | number, index: number) => void;
};

function VariantSwitcherImpl({ messageId, index, total, onSelect }: Props) {
    const canPrev = index > 0;
    const canNext = index < total - 1;

    return (
        <div className="flex items-center text-xs text-gray-400 dark:text-gray-500">
            <button
                type="button"
                onClick={() => canPrev && onSelect(messageId, index - 1)}
                disabled={!canPrev}
                className="rounded px-1 transition-colors hover:text-gray-600 disabled:cursor-default disabled:opacity-40 disabled:hover:text-gray-400 dark:hover:text-gray-300 dark:disabled:hover:text-gray-500"
                aria-label="Previous response"
            >
                ‹
            </button>
            <span className="tabular-nums" aria-live="polite">
                {index + 1}/{total}
            </span>
            <button
                type="button"
                onClick={() => canNext && onSelect(messageId, index + 1)}
                disabled={!canNext}
                className="rounded px-1 transition-colors hover:text-gray-600 disabled:cursor-default disabled:opacity-40 disabled:hover:text-gray-400 dark:hover:text-gray-300 dark:disabled:hover:text-gray-500"
                aria-label="Next response"
            >
                ›
            </button>
        </div>
    );
}

export const VariantSwitcher = memo(VariantSwitcherImpl);
