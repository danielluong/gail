import { useEffect, useState } from 'react';

export function ThinkingIndicator({ status }: { status?: string }) {
    const [dots, setDots] = useState(0);

    useEffect(() => {
        const interval = setInterval(() => {
            setDots((prev) => (prev + 1) % 4);
        }, 300);

        return () => clearInterval(interval);
    }, []);

    const label = status ?? 'Thinking';

    return (
        <p className="animate-pulse text-gray-500">
            {label}
            {'.'.repeat(dots)}
        </p>
    );
}
