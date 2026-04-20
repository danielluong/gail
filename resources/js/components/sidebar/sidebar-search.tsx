import type { RefObject } from 'react';
import { CloseIcon, SearchIcon } from '@/components/icons';

export function SidebarSearch({
    value,
    onChange,
    onClear,
    inputRef,
}: {
    value: string;
    onChange: (value: string) => void;
    onClear: () => void;
    inputRef?: RefObject<HTMLInputElement | null>;
}) {
    return (
        <div className="mb-2 px-1">
            <div className="relative">
                <span className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-gray-500">
                    <SearchIcon />
                </span>
                <input
                    ref={inputRef}
                    type="text"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="Search..."
                    className="w-full rounded-lg border border-transparent bg-gray-100 py-1.5 pr-3 pl-8 text-xs text-gray-700 placeholder-gray-400 focus:border-gray-300 focus:ring-0 focus:outline-none dark:bg-surface-250 dark:text-gray-200 dark:placeholder-gray-500 dark:focus:border-surface-600"
                />
                {value && (
                    <button
                        type="button"
                        onClick={onClear}
                        aria-label="Clear search"
                        className="absolute top-1/2 right-2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                    >
                        <CloseIcon />
                    </button>
                )}
            </div>
        </div>
    );
}
