import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CheckIcon, ChevronRightIcon } from '@/components/icons';

export type ComboOption = { key: string; label: string };

export function ComboSelect({
    value,
    options,
    onChange,
    disabled = false,
}: {
    value: string;
    options: ComboOption[];
    onChange: (key: string) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState({ bottom: 0, left: 0 });
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        function handleClickOutside(e: MouseEvent) {
            const target = e.target as Node;

            if (
                buttonRef.current?.contains(target) ||
                menuRef.current?.contains(target)
            ) {
                return;
            }

            setOpen(false);
        }

        document.addEventListener('click', handleClickOutside);

        return () => document.removeEventListener('click', handleClickOutside);
    }, [open]);

    if (options.length === 0) {
        return null;
    }

    const current = options.find((o) => o.key === value) ?? options[0];

    return (
        <>
            <button
                ref={buttonRef}
                type="button"
                onClick={() => {
                    if (disabled) {
                        return;
                    }

                    if (!open && buttonRef.current) {
                        const rect = buttonRef.current.getBoundingClientRect();
                        setPosition({
                            bottom: window.innerHeight - rect.top + 4,
                            left: rect.left,
                        });
                    }

                    setOpen((prev) => !prev);
                }}
                disabled={disabled}
                className="flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-surface-400 dark:hover:text-gray-200"
            >
                {current.label}
                <ChevronRightIcon
                    className={`size-2.5 transition-transform ${open ? '-rotate-90' : 'rotate-90'}`}
                />
            </button>

            {open &&
                createPortal(
                    <div
                        ref={menuRef}
                        className="fixed z-50 max-h-60 w-56 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-surface-500 dark:bg-surface-250"
                        style={{
                            bottom: position.bottom,
                            left: position.left,
                        }}
                    >
                        {options.map((option) => {
                            const active = option.key === value;

                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => {
                                        onChange(option.key);
                                        setOpen(false);
                                    }}
                                    className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-surface-400 ${
                                        active
                                            ? 'text-gray-900 dark:text-white'
                                            : 'text-gray-600 dark:text-gray-300'
                                    }`}
                                >
                                    {active && <CheckIcon />}
                                    <span className={active ? '' : 'ml-5'}>
                                        {option.label}
                                    </span>
                                </button>
                            );
                        })}
                    </div>,
                    document.body,
                )}
        </>
    );
}
