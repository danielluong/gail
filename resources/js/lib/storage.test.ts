import { afterEach, describe, expect, it } from 'vitest';
import { getStored, removeStored, setStored } from './storage';

afterEach(() => {
    localStorage.clear();
});

describe('getStored / setStored / removeStored', () => {
    it('returns the fallback when the key is missing', () => {
        expect(getStored('missing', 'fallback')).toBe('fallback');
    });

    it('round-trips a string value', () => {
        setStored('k', 'hello');
        expect(getStored('k', '')).toBe('hello');
    });

    it('round-trips an object value', () => {
        const value = { a: 1, b: ['x', 'y'] };
        setStored('obj', value);
        expect(getStored('obj', null)).toEqual(value);
    });

    it('round-trips a number', () => {
        setStored('n', 42);
        expect(getStored('n', 0)).toBe(42);
    });

    it('returns the fallback when stored value is corrupted JSON', () => {
        localStorage.setItem('broken', '{not-json');
        expect(getStored('broken', 'fallback')).toBe('fallback');
    });

    it('removeStored clears the key', () => {
        setStored('k', 'v');
        removeStored('k');
        expect(getStored('k', 'fallback')).toBe('fallback');
    });

    it('setStored tolerates a quota-exceeded failure without throwing', () => {
        const original = localStorage.setItem.bind(localStorage);
        localStorage.setItem = () => {
            throw new Error('QuotaExceeded');
        };

        expect(() => setStored('k', 'v')).not.toThrow();

        localStorage.setItem = original;
    });
});
