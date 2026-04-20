import { useEffect } from 'react';

export type Shortcut = {
    key: string;
    meta?: boolean;
    shift?: boolean;
    alt?: boolean;
    handler: (event: KeyboardEvent) => void;
    allowInInput?: boolean;
};

function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    return (
        tag === 'INPUT' ||
        tag === 'TEXTAREA' ||
        tag === 'SELECT' ||
        target.isContentEditable
    );
}

export function useKeyboardShortcuts(shortcuts: Shortcut[]): void {
    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            const inEditable = isEditableTarget(event.target);

            for (const shortcut of shortcuts) {
                if (event.key.toLowerCase() !== shortcut.key.toLowerCase()) {
                    continue;
                }

                const metaPressed = event.metaKey || event.ctrlKey;

                if ((shortcut.meta ?? false) !== metaPressed) {
                    continue;
                }

                if ((shortcut.shift ?? false) !== event.shiftKey) {
                    continue;
                }

                if ((shortcut.alt ?? false) !== event.altKey) {
                    continue;
                }

                if (inEditable && !shortcut.allowInInput) {
                    continue;
                }

                event.preventDefault();
                shortcut.handler(event);

                return;
            }
        }

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [shortcuts]);
}
