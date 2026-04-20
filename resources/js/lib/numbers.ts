const numberFormatter = new Intl.NumberFormat();

/**
 * Locale-aware thousands-separated number formatter. Used across the
 * analytics dashboard and anywhere else we display counts.
 */
export function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

/*
 * Format a USD cost. Fractions-of-a-cent need enough precision to be
 * distinguishable (a single cheap-model turn might be $0.0003), while
 * larger totals look cleaner without five trailing zeros.
 */
export function formatCost(value: number): string {
    if (value === 0) {
        return '$0.00';
    }

    if (Math.abs(value) < 0.01) {
        return `$${value.toFixed(4)}`;
    }

    if (Math.abs(value) < 1) {
        return `$${value.toFixed(3)}`;
    }

    return `$${value.toFixed(2)}`;
}
