import { useEffect, useRef, useState } from 'react';

export function InlineEditor({
    initialValue,
    onSave,
    onCancel,
    className = '',
}: {
    initialValue: string;
    onSave: (value: string) => void;
    onCancel: () => void;
    className?: string;
}) {
    const [value, setValue] = useState(initialValue);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        inputRef.current?.focus();
        inputRef.current?.select();
    }, []);

    function submit() {
        const trimmed = value.trim();

        if (!trimmed || trimmed === initialValue) {
            onCancel();

            return;
        }

        onSave(trimmed);
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            className={className}
        >
            <input
                ref={inputRef}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                onBlur={submit}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        onCancel();
                    }
                }}
                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-gray-400 focus:ring-0 focus:outline-none dark:border-surface-600 dark:bg-surface-250 dark:text-gray-100 dark:focus:border-surface-700"
            />
        </form>
    );
}
