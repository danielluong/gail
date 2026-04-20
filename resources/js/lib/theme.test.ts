import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { applyTheme, getStoredTheme } from './theme';

beforeEach(() => {
    localStorage.clear();
    document.documentElement.classList.remove('dark');
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('getStoredTheme', () => {
    it('returns the stored theme when present', () => {
        localStorage.setItem('gail-theme', 'light');
        expect(getStoredTheme()).toBe('light');

        localStorage.setItem('gail-theme', 'dark');
        expect(getStoredTheme()).toBe('dark');
    });

    it('falls back to system preference when no theme is stored (dark)', () => {
        vi.spyOn(window, 'matchMedia').mockReturnValue({
            matches: true,
        } as MediaQueryList);

        expect(getStoredTheme()).toBe('dark');
    });

    it('falls back to system preference when no theme is stored (light)', () => {
        vi.spyOn(window, 'matchMedia').mockReturnValue({
            matches: false,
        } as MediaQueryList);

        expect(getStoredTheme()).toBe('light');
    });

    it('ignores garbage values in storage and uses system preference', () => {
        localStorage.setItem('gail-theme', 'neon');
        vi.spyOn(window, 'matchMedia').mockReturnValue({
            matches: false,
        } as MediaQueryList);

        expect(getStoredTheme()).toBe('light');
    });
});

describe('applyTheme', () => {
    it('adds the dark class and persists the choice for dark', () => {
        applyTheme('dark');

        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(localStorage.getItem('gail-theme')).toBe('dark');
    });

    it('removes the dark class and persists the choice for light', () => {
        document.documentElement.classList.add('dark');

        applyTheme('light');

        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(localStorage.getItem('gail-theme')).toBe('light');
    });
});
