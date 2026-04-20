import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { SettingsIcon } from '@/components/icons';

export function SettingsPanel({
    temperature,
    onTemperatureChange,
    disabled,
}: {
    temperature: number;
    onTemperatureChange: (value: number) => void;
    disabled: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState({ bottom: 0, left: 0 });
    const buttonRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        function handleClickOutside(e: MouseEvent) {
            const target = e.target as Node;

            if (
                buttonRef.current?.contains(target) ||
                panelRef.current?.contains(target)
            ) {
                return;
            }

            setOpen(false);
        }

        document.addEventListener('click', handleClickOutside);

        return () => document.removeEventListener('click', handleClickOutside);
    }, [open]);

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
                aria-label="Generation settings"
                className="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 disabled:opacity-50 dark:text-gray-500 dark:hover:bg-surface-400 dark:hover:text-gray-300"
            >
                <SettingsIcon />
            </button>

            {open &&
                createPortal(
                    <div
                        ref={panelRef}
                        className="fixed z-50 w-64 rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-surface-500 dark:bg-surface-250"
                        style={{
                            bottom: position.bottom,
                            left: position.left,
                        }}
                    >
                        <label
                            htmlFor="temperature-slider"
                            className="flex items-center justify-between text-xs font-medium text-gray-700 dark:text-gray-200"
                        >
                            <span>Temperature</span>
                            <span className="text-gray-500 tabular-nums dark:text-gray-400">
                                {temperature.toFixed(1)}
                            </span>
                        </label>
                        <input
                            id="temperature-slider"
                            type="range"
                            min="0"
                            max="2"
                            step="0.1"
                            value={temperature}
                            onChange={(e) =>
                                onTemperatureChange(Number(e.target.value))
                            }
                            className="mt-2 w-full accent-orange-500"
                        />
                        <div className="mt-1 flex justify-between text-[10px] text-gray-400 dark:text-gray-500">
                            <span>Focused</span>
                            <span>Balanced</span>
                            <span>Creative</span>
                        </div>
                        <p className="mt-2 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">
                            Lower values produce more deterministic answers;
                            higher values produce more varied ones.
                        </p>
                    </div>,
                    document.body,
                )}
        </>
    );
}
