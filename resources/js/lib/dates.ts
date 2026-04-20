import type { Conversation } from '@/types/chat';

export type ConversationGroup = {
    label: string;
    items: Conversation[];
};

const DAY_MS = 24 * 60 * 60 * 1000;

function startOfDay(date: Date): number {
    const d = new Date(date);
    d.setHours(0, 0, 0, 0);

    return d.getTime();
}

function monthLabel(date: Date, currentYear: number): string {
    return date.toLocaleDateString(undefined, {
        month: 'long',
        year: date.getFullYear() === currentYear ? undefined : 'numeric',
    });
}

function bucketFor(updatedAt: string, now: Date): string {
    const date = new Date(updatedAt);
    const todayStart = startOfDay(now);
    const diffDays = Math.floor((todayStart - startOfDay(date)) / DAY_MS);

    if (diffDays <= 0) {
        return 'Today';
    }

    if (diffDays === 1) {
        return 'Yesterday';
    }

    if (diffDays <= 7) {
        return 'Previous 7 Days';
    }

    if (diffDays <= 30) {
        return 'Previous 30 Days';
    }

    return monthLabel(date, now.getFullYear());
}

export function groupConversationsByDate(
    conversations: Conversation[],
): ConversationGroup[] {
    if (conversations.length === 0) {
        return [];
    }

    const now = new Date();
    const buckets = new Map<string, Conversation[]>();

    for (const convo of conversations) {
        const label = bucketFor(convo.updated_at, now);
        const bucket = buckets.get(label);

        if (bucket) {
            bucket.push(convo);
        } else {
            buckets.set(label, [convo]);
        }
    }

    return Array.from(buckets.entries()).map(([label, items]) => ({
        label,
        items,
    }));
}

/**
 * Short "Jan 5" style day label. Used for analytics chart ticks and
 * anywhere else that needs a compact day identifier.
 */
export function formatDayLabel(iso: string): string {
    const d = new Date(`${iso}T00:00:00`);

    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/**
 * Human-friendly relative time ("just now", "5m ago", "3d ago") with
 * a locale-aware calendar date fallback for anything beyond a week.
 */
export function formatRelativeTime(iso: string): string {
    const date = new Date(iso);
    const now = Date.now();
    const diffSec = Math.round((now - date.getTime()) / 1000);

    if (diffSec < 5) {
        return 'just now';
    }

    if (diffSec < 60) {
        return `${diffSec}s ago`;
    }

    const diffMin = Math.round(diffSec / 60);

    if (diffMin < 60) {
        return `${diffMin}m ago`;
    }

    const diffHr = Math.round(diffMin / 60);

    if (diffHr < 24) {
        return `${diffHr}h ago`;
    }

    const diffDays = Math.round(diffHr / 24);

    if (diffDays < 7) {
        return `${diffDays}d ago`;
    }

    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year:
            date.getFullYear() === new Date().getFullYear()
                ? undefined
                : 'numeric',
    });
}
