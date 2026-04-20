import { describe, expect, it } from 'vitest';
import { formatCost, formatNumber } from './numbers';

describe('formatCost', () => {
    it('formats zero as dollars and cents', () => {
        expect(formatCost(0)).toBe('$0.00');
    });

    it('uses four decimals for sub-cent amounts', () => {
        expect(formatCost(0.0034)).toBe('$0.0034');
    });

    it('uses three decimals between one cent and one dollar', () => {
        expect(formatCost(0.125)).toBe('$0.125');
    });

    it('uses two decimals for amounts of one dollar or more', () => {
        expect(formatCost(12.5)).toBe('$12.50');
        expect(formatCost(1.234)).toBe('$1.23');
    });
});

describe('formatNumber', () => {
    it('formats small integers without separators', () => {
        expect(formatNumber(0)).toBe('0');
        expect(formatNumber(7)).toBe('7');
        expect(formatNumber(999)).toBe('999');
    });

    it('adds a thousands separator for four-digit numbers', () => {
        const result = formatNumber(1234);
        expect(result).toMatch(/1.234/);
    });

    it('formats large numbers with multiple separators', () => {
        const result = formatNumber(1234567);
        expect(result).toMatch(/1.234.567/);
    });

    it('handles negative numbers', () => {
        expect(formatNumber(-42)).toBe('-42');
    });
});
