const number = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 0 });
const oneDecimal = new Intl.NumberFormat('en-GB', {
    maximumFractionDigits: 1,
});

export function formatKm(km: number | null | undefined): string {
    if (km === null || km === undefined) {
        return '–';
    }

    return `${km >= 100 ? number.format(km) : oneDecimal.format(km)} km`;
}

/** ETS2 pays in euros. */
export function formatMoney(amount: number | null | undefined): string {
    if (amount === null || amount === undefined) {
        return '–';
    }

    return `€${number.format(amount)}`;
}

export function formatCount(n: number): string {
    return number.format(n);
}

/** "1 job", "2 jobs", "1,204 jobs". */
export function plural(n: number, one: string, many = `${one}s`): string {
    return `${number.format(n)} ${n === 1 ? one : many}`;
}

export function formatTonnes(kg: number | null | undefined): string {
    if (kg === null || kg === undefined) {
        return '–';
    }

    return `${oneDecimal.format(kg / 1000)} t`;
}

export function formatDuration(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);

    return h > 0 ? `${h} h ${m} min` : `${m} min`;
}

export function formatDate(iso: string | null | undefined): string {
    if (!iso) {
        return '–';
    }

    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

export function timeAgo(iso: string | null | undefined): string {
    if (!iso) {
        return 'never';
    }

    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 60) {
        return relative.format(seconds, 'second');
    }

    if (abs < 3600) {
        return relative.format(Math.round(seconds / 60), 'minute');
    }

    if (abs < 86400) {
        return relative.format(Math.round(seconds / 3600), 'hour');
    }

    if (abs < 86400 * 30) {
        return relative.format(Math.round(seconds / 86400), 'day');
    }

    return formatDate(iso);
}
