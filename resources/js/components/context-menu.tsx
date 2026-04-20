import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CheckIcon, ChevronRightIcon, DotsIcon } from './icons';

const VIEWPORT_MARGIN = 8;

export type MenuItem = {
    label: string;
    onClick?: () => void;
    danger?: boolean;
    icon: React.ReactNode;
    submenu?: { label: string; onClick: () => void; active?: boolean }[];
};

let nextMenuId = 0;
const menuOpenEvent = new EventTarget();

function SubmenuItem({
    item,
    onClose,
}: {
    item: MenuItem;
    onClose: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState<{
        top: number;
        left?: number;
        right?: number;
    }>({ top: 0, left: 0 });
    const triggerRef = useRef<HTMLButtonElement>(null);
    const submenuRef = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        if (!open || !triggerRef.current || !submenuRef.current) {
            return;
        }

        const triggerRect = triggerRef.current.getBoundingClientRect();
        const submenuRect = submenuRef.current.getBoundingClientRect();
        const openLeft =
            triggerRect.right + submenuRect.width >
            window.innerWidth - VIEWPORT_MARGIN;
        const top = Math.min(
            triggerRect.top,
            window.innerHeight - submenuRect.height - VIEWPORT_MARGIN,
        );

        // eslint-disable-next-line react-hooks/set-state-in-effect
        setPosition(
            openLeft
                ? { top, right: window.innerWidth - triggerRect.left }
                : { top, left: triggerRect.right },
        );
    }, [open]);

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                onMouseEnter={() => {
                    if (triggerRef.current) {
                        const rect = triggerRef.current.getBoundingClientRect();
                        setPosition({ top: rect.top, left: rect.right });
                    }

                    setOpen(true);
                }}
                onMouseLeave={(e) => {
                    const related = e.relatedTarget as Node | null;

                    if (
                        !related ||
                        !document
                            .querySelector('[data-submenu]')
                            ?.contains(related)
                    ) {
                        setOpen(false);
                    }
                }}
                className={`flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm text-gray-700 dark:text-gray-200 ${
                    open
                        ? 'bg-gray-100 dark:bg-surface-400'
                        : 'hover:bg-gray-100 dark:hover:bg-surface-400'
                }`}
            >
                <span className="flex items-center gap-2">
                    {item.icon}
                    {item.label}
                </span>
                <ChevronRightIcon className="size-3 text-gray-500" />
            </button>
            {open &&
                createPortal(
                    <div
                        ref={submenuRef}
                        data-submenu
                        onMouseLeave={() => setOpen(false)}
                        className={`fixed z-50 ${position.right !== undefined ? 'pr-1' : 'pl-1'}`}
                        style={{
                            top: position.top,
                            left: position.left,
                            right: position.right,
                        }}
                    >
                        <div className="max-h-60 w-44 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-surface-500 dark:bg-surface-250">
                            {item.submenu?.map((sub) => (
                                <button
                                    key={sub.label}
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        e.preventDefault();
                                        setOpen(false);
                                        onClose();
                                        sub.onClick();
                                    }}
                                    className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-surface-400 ${
                                        sub.active
                                            ? 'text-gray-900 dark:text-white'
                                            : 'text-gray-700 dark:text-gray-200'
                                    }`}
                                >
                                    {sub.active && <CheckIcon />}
                                    <span className={sub.active ? '' : 'ml-5'}>
                                        {sub.label}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>,
                    document.body,
                )}
        </>
    );
}

export function ContextMenu({ items }: { items: MenuItem[] }) {
    const [menuId] = useState(() => ++nextMenuId);
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState({ top: 0, left: 0 });
    const menuRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        function handleOtherMenuOpened(e: Event) {
            if ((e as CustomEvent<number>).detail !== menuId) {
                setOpen(false);
            }
        }
        menuOpenEvent.addEventListener('open', handleOtherMenuOpened);

        return () =>
            menuOpenEvent.removeEventListener('open', handleOtherMenuOpened);
    }, [menuId]);

    useEffect(() => {
        if (!open) {
            return;
        }

        function handleClickOutside(e: MouseEvent) {
            const target = e.target as Node;

            if (
                menuRef.current &&
                !menuRef.current.contains(target) &&
                buttonRef.current &&
                !buttonRef.current.contains(target)
            ) {
                setOpen(false);
            }
        }
        document.addEventListener('click', handleClickOutside);

        return () => document.removeEventListener('click', handleClickOutside);
    }, [open]);

    useLayoutEffect(() => {
        if (!open || !buttonRef.current || !menuRef.current) {
            return;
        }

        const triggerRect = buttonRef.current.getBoundingClientRect();
        const menuRect = menuRef.current.getBoundingClientRect();
        const spaceBelow = window.innerHeight - triggerRect.bottom;
        const openUp =
            spaceBelow < menuRect.height + VIEWPORT_MARGIN &&
            triggerRect.top > menuRect.height + VIEWPORT_MARGIN;

        setPosition({
            top: openUp
                ? triggerRect.top - menuRect.height - 4
                : triggerRect.bottom + 4,
            left: Math.max(
                VIEWPORT_MARGIN,
                Math.min(
                    triggerRect.right - menuRect.width,
                    window.innerWidth - menuRect.width - VIEWPORT_MARGIN,
                ),
            ),
        });
    }, [open]);

    return (
        <>
            <button
                ref={buttonRef}
                type="button"
                onClick={(e) => {
                    e.stopPropagation();
                    e.preventDefault();

                    if (!open && buttonRef.current) {
                        const rect = buttonRef.current.getBoundingClientRect();
                        setPosition({
                            top: rect.bottom + 4,
                            left: rect.right - 144,
                        });
                        menuOpenEvent.dispatchEvent(
                            new CustomEvent('open', { detail: menuId }),
                        );
                    }

                    setOpen((prev) => !prev);
                }}
                className="rounded p-1 text-gray-400 opacity-0 transition-opacity group-hover:opacity-100 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                aria-label="Options"
            >
                <DotsIcon />
            </button>
            {open &&
                createPortal(
                    <div
                        ref={menuRef}
                        className="fixed z-50 w-40 rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-surface-500 dark:bg-surface-250"
                        style={{ top: position.top, left: position.left }}
                    >
                        {items.map((item) =>
                            item.submenu ? (
                                <SubmenuItem
                                    key={item.label}
                                    item={item}
                                    onClose={() => setOpen(false)}
                                />
                            ) : (
                                <button
                                    key={item.label}
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        e.preventDefault();
                                        setOpen(false);
                                        item.onClick?.();
                                    }}
                                    className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-surface-400 ${
                                        item.danger
                                            ? 'text-red-500 dark:text-red-400'
                                            : 'text-gray-700 dark:text-gray-200'
                                    }`}
                                >
                                    {item.icon}
                                    {item.label}
                                </button>
                            ),
                        )}
                    </div>,
                    document.body,
                )}
        </>
    );
}
