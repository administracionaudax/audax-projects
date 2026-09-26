/**
 * Formateo de la interfaz (SPEC §3): es-ES, Europe/Madrid, semana desde el lunes.
 * - Las horas se guardan en minutos enteros y se muestran como h:mm.
 * - Los días de imputación (YYYY-MM-DD) son fechas locales sin hora: no se convierten de zona.
 * - Los instantes (ISO con hora) se guardan en UTC y se muestran en Europe/Madrid.
 */

export const LOCALE = 'es-ES';
export const TIME_ZONE = 'Europe/Madrid';

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** 150 → "2:30"; 0 → "0:00"; 1500 → "25:00"; -30 → "-0:30". */
export function formatMinutes(minutes: number): string {
    if (!Number.isFinite(minutes)) {
        return '';
    }

    const sign = minutes < 0 ? '-' : '';
    const total = Math.round(Math.abs(minutes));
    const hours = Math.floor(total / 60);
    const rest = total % 60;

    return `${sign}${hours}:${String(rest).padStart(2, '0')}`;
}

const dateFormatter = new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const dateTimeFormatter = new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

/** "2026-09-26" → "26/09/2026" (sin conversión); instante ISO → fecha en Europe/Madrid. */
export function formatDate(value: string | Date | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    if (typeof value === 'string') {
        const match = DATE_ONLY.exec(value);

        if (match) {
            return `${match[3]}/${match[2]}/${match[1]}`;
        }
    }

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? '' : dateFormatter.format(date);
}

/** Instante → "26/09/2026 14:05" en Europe/Madrid. */
export function formatDateTime(
    value: string | Date | null | undefined,
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return dateTimeFormatter.format(date).replace(',', '');
}

const currencyFormatter = new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: 'EUR',
    useGrouping: 'always',
});

/** 1234.56 → "1.234,56 €". Acepta los decimales que llegan de Laravel como string. */
export function formatCurrency(
    amount: number | string | null | undefined,
): string {
    if (amount === null || amount === undefined || amount === '') {
        return '';
    }

    const value = typeof amount === 'string' ? Number(amount) : amount;

    return Number.isFinite(value) ? currencyFormatter.format(value) : '';
}

/** 1234.5 → "1.234,5". */
export function formatNumber(value: number, maximumFractionDigits = 2): string {
    return new Intl.NumberFormat(LOCALE, {
        maximumFractionDigits,
        useGrouping: 'always',
    }).format(value);
}

/** 0.756 → "75,6 %". Admite valores > 1 (p. ej. consumo de bolsa 104 %). */
export function formatPercent(
    ratio: number,
    maximumFractionDigits = 1,
): string {
    if (!Number.isFinite(ratio)) {
        return '';
    }

    return new Intl.NumberFormat(LOCALE, {
        style: 'percent',
        maximumFractionDigits,
    }).format(ratio);
}
