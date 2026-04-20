import { useEffect, useState } from 'react';

type Toast = {
    id: number;
    message: string;
    type: 'error' | 'success';
};

const TOAST_EVENT = 'gail:toast';
const toastBus = new EventTarget();
let nextToastId = 0;

export function showToast(
    message: string,
    type: 'error' | 'success' = 'error',
) {
    toastBus.dispatchEvent(
        new CustomEvent<Toast>(TOAST_EVENT, {
            detail: { id: nextToastId++, message, type },
        }),
    );
}

export function ToastContainer() {
    const [toasts, setToasts] = useState<Toast[]>([]);

    useEffect(() => {
        function handle(event: Event) {
            const toast = (event as CustomEvent<Toast>).detail;
            setToasts((prev) => [...prev, toast]);
        }

        toastBus.addEventListener(TOAST_EVENT, handle);

        return () => {
            toastBus.removeEventListener(TOAST_EVENT, handle);
        };
    }, []);

    function dismiss(id: number) {
        setToasts((prev) => prev.filter((t) => t.id !== id));
    }

    return (
        <div
            className="fixed right-4 bottom-4 z-50 flex flex-col gap-2"
            role="region"
            aria-label="Notifications"
            aria-live="polite"
        >
            {toasts.map((toast) => (
                <ToastItem
                    key={toast.id}
                    toast={toast}
                    onDismiss={() => dismiss(toast.id)}
                />
            ))}
        </div>
    );
}

function ToastItem({
    toast,
    onDismiss,
}: {
    toast: Toast;
    onDismiss: () => void;
}) {
    useEffect(() => {
        const timer = setTimeout(onDismiss, 4000);

        return () => clearTimeout(timer);
    }, [onDismiss]);

    return (
        <div
            role={toast.type === 'error' ? 'alert' : 'status'}
            className={`flex items-center gap-2 rounded-lg px-4 py-3 text-sm shadow-lg ${
                toast.type === 'error'
                    ? 'bg-red-600 text-white'
                    : 'bg-green-600 text-white'
            }`}
        >
            <span>{toast.message}</span>
            <button
                type="button"
                onClick={onDismiss}
                className="ml-2 opacity-70 hover:opacity-100"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={2}
                    stroke="currentColor"
                    className="size-4"
                >
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M6 18 18 6M6 6l12 12"
                    />
                </svg>
            </button>
        </div>
    );
}
