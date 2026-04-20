export function getStored<T>(key: string, fallback: T): T {
    if (typeof window === 'undefined') {
        return fallback;
    }

    const raw = localStorage.getItem(key);

    if (raw === null) {
        return fallback;
    }

    try {
        return JSON.parse(raw) as T;
    } catch {
        return fallback;
    }
}

export function setStored<T>(key: string, value: T): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // ignore quota errors
    }
}

export function removeStored(key: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.removeItem(key);
}
