const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

const rtf =
    typeof Intl !== 'undefined'
        ? new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
        : null;

/** "2 days ago", "just now" — no "Saved" prefix. */
export function relativeTime(iso: string, now: Date = new Date()): string {
    const seconds = Math.round(
        (new Date(iso).getTime() - now.getTime()) / 1000,
    );
    const abs = Math.abs(seconds);
    if (abs < 45) {
        return 'just now';
    }
    for (const [unit, size] of UNITS) {
        if (abs >= size || unit === 'minute') {
            const value = Math.round(seconds / size);

            return rtf
                ? rtf.format(value, unit)
                : `${Math.abs(value)} ${unit}s ago`;
        }
    }

    return 'just now';
}

/** Exact timestamp in the user's display timezone. */
export function exactTime(iso: string, timeZone: string): string {
    try {
        return new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone,
        }).format(new Date(iso));
    } catch {
        return new Date(iso).toISOString();
    }
}

export function shortDate(iso: string, timeZone: string): string {
    try {
        return new Intl.DateTimeFormat('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            timeZone,
        }).format(new Date(iso));
    } catch {
        return iso.slice(0, 10);
    }
}

export function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) {
        return '0 B';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let i = 0;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }

    return `${i === 0 ? value : value.toFixed(1)} ${units[i]}`;
}

export function formatNumber(n: number | null | undefined): string {
    return new Intl.NumberFormat('en').format(n ?? 0);
}

export function pluralize(n: number, word: string, plural?: string): string {
    return `${formatNumber(n)} ${n === 1 ? word : (plural ?? `${word}s`)}`;
}
