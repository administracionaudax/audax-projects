/**
 * Etiquetas de los días de la hoja semanal (fechas locales "YYYY-MM-DD", sin conversión de zona).
 */
import { LOCALE } from '@/lib/format';

function utcDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day));
}

const weekdayShort = new Intl.DateTimeFormat(LOCALE, {
    weekday: 'short',
    timeZone: 'UTC',
});

const weekdayLong = new Intl.DateTimeFormat(LOCALE, {
    weekday: 'long',
    timeZone: 'UTC',
});

const dayMonthYear = new Intl.DateTimeFormat(LOCALE, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

const dayMonthShort = new Intl.DateTimeFormat(LOCALE, {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

/** "lun." */
export function weekdayShortLabel(date: string): string {
    return weekdayShort.format(utcDate(date));
}

/** "lunes" */
export function weekdayLongLabel(date: string): string {
    return weekdayLong.format(utcDate(date));
}

/** "21/09" (sin conversión de zona: se toma de la propia fecha). */
export function dayMonthLabel(date: string): string {
    const [, month, day] = date.split('-');

    return `${day}/${month}`;
}

/** "21 sept – 27 sept 2026" */
export function weekRangeLabel(start: string, end: string): string {
    return `${dayMonthShort.format(utcDate(start))} – ${dayMonthYear.format(utcDate(end))}`;
}
