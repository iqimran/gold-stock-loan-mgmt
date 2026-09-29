/**
 * Formats a decimal money string for display without converting through floating point arithmetic.
 * The server is authoritative for every amount; this only groups digits.
 */
export function formatMoney(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const [whole, fraction = ''] = value.split('.');
    const negative = whole.startsWith('-');
    const digits = negative ? whole.slice(1) : whole;
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${negative ? '-' : ''}${grouped}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}

/**
 * Formats a date-only value (YYYY-MM-DD) without shifting it through the browser's time zone.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export function formatDateTime(value: string | null | undefined): string {
    return value ? new Date(value).toLocaleString() : '—';
}

/**
 * Formats a decimal rate string without trailing zeros ("2.0000" → "2", "2.5800" → "2.58").
 * Works on the string so no precision is lost.
 */
export function formatRate(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return value.includes('.') ? value.replace(/\.?0+$/, '') : value;
}
