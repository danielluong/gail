import { useEffect, useState } from 'react';

/**
 * Reactively observe Tailwind's `dark` class on the document root.
 * Components that need to branch on theme (e.g. syntax highlighting
 * color schemes) subscribe via this hook rather than each spinning up
 * its own MutationObserver.
 */
export function useThemeMode(): 'dark' | 'light' {
    const [mode, setMode] = useState<'dark' | 'light'>(() => currentMode());

    useEffect(() => {
        const observer = new MutationObserver(() => setMode(currentMode()));

        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });

        return () => observer.disconnect();
    }, []);

    return mode;
}

function currentMode(): 'dark' | 'light' {
    if (typeof document === 'undefined') {
        return 'light';
    }

    return document.documentElement.classList.contains('dark')
        ? 'dark'
        : 'light';
}
