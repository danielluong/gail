import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Conversation } from '@/types/chat';
import {
    formatDayLabel,
    formatRelativeTime,
    groupConversationsByDate,
} from './dates';

function convo(id: string, updated_at: string): Conversation {
    return {
        id,
        title: id,
        updated_at,
        project_id: null,
        parent_id: null,
        is_pinned: false,
    };
}

describe('formatRelativeTime', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-10T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('returns "just now" for a timestamp within 5 seconds', () => {
        expect(formatRelativeTime('2026-04-10T11:59:58Z')).toBe('just now');
    });

    it('returns seconds ago for < 1 minute', () => {
        expect(formatRelativeTime('2026-04-10T11:59:30Z')).toBe('30s ago');
    });

    it('returns minutes ago for < 1 hour', () => {
        expect(formatRelativeTime('2026-04-10T11:30:00Z')).toBe('30m ago');
    });

    it('returns hours ago for < 24 hours', () => {
        expect(formatRelativeTime('2026-04-10T09:00:00Z')).toBe('3h ago');
    });

    it('returns days ago for < 7 days', () => {
        expect(formatRelativeTime('2026-04-07T12:00:00Z')).toBe('3d ago');
    });

    it('falls back to a calendar date for timestamps older than a week', () => {
        const result = formatRelativeTime('2026-03-01T12:00:00Z');
        expect(result).not.toMatch(/ago/);
        expect(result.length).toBeGreaterThan(0);
    });
});

describe('formatDayLabel', () => {
    it('renders a short month-day label for an ISO date string', () => {
        const result = formatDayLabel('2026-04-10');
        expect(result).toMatch(/\d+/);
        expect(result.length).toBeGreaterThan(0);
    });
});

describe('groupConversationsByDate', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-10T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('returns an empty array for no conversations', () => {
        expect(groupConversationsByDate([])).toEqual([]);
    });

    it('buckets conversations into Today / Yesterday / Previous 7 / Previous 30 / month', () => {
        const groups = groupConversationsByDate([
            convo('a', '2026-04-10T10:00:00Z'), // Today
            convo('b', '2026-04-09T10:00:00Z'), // Yesterday
            convo('c', '2026-04-05T10:00:00Z'), // Previous 7 Days
            convo('d', '2026-03-20T10:00:00Z'), // Previous 30 Days
            convo('e', '2026-01-15T10:00:00Z'), // Month label
        ]);

        const labels = groups.map((g) => g.label);
        expect(labels).toContain('Today');
        expect(labels).toContain('Yesterday');
        expect(labels).toContain('Previous 7 Days');
        expect(labels).toContain('Previous 30 Days');
        expect(labels.length).toBe(5);
    });

    it('groups multiple conversations into the same bucket', () => {
        const groups = groupConversationsByDate([
            convo('a', '2026-04-10T10:00:00Z'),
            convo('b', '2026-04-10T11:00:00Z'),
        ]);

        expect(groups).toHaveLength(1);
        expect(groups[0].label).toBe('Today');
        expect(groups[0].items.map((i) => i.id)).toEqual(['a', 'b']);
    });
});
