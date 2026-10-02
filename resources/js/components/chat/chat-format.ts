import { formatDate, LOCALE, TIME_ZONE } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Fechas y horas del chat, siempre en Europe/Madrid (lib/format.ts): hora de cada mensaje,
 * separadores de día («Hoy», «Ayer», «lunes, 21 de septiembre») y la hora corta de la lista.
 */

const timeFormatter = new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIME_ZONE,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

const dayKeyFormatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

const longDayFormatter = new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIME_ZONE,
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

const longDayWithYearFormatter = new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIME_ZONE,
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

const weekdayFormatter = new Intl.DateTimeFormat(LOCALE, {
    timeZone: TIME_ZONE,
    weekday: 'short',
});

function toDate(value: string | Date): Date | null {
    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

/** "14:05" en Madrid. */
export function formatMessageTime(value: string | Date | null): string {
    const date = value === null ? null : toDate(value);

    return date ? timeFormatter.format(date) : '';
}

/** Día en Madrid como "2026-09-27" (para agrupar mensajes por día). */
export function dayKey(value: string | Date): string {
    const date = toDate(value);

    return date ? dayKeyFormatter.format(date) : '';
}

/** Días naturales (en Madrid) entre dos claves "YYYY-MM-DD". */
function daysBetween(from: string, to: string): number {
    const [fy, fm, fd] = from.split('-').map(Number);
    const [ty, tm, td] = to.split('-').map(Number);

    return Math.round(
        (Date.UTC(ty, tm - 1, td) - Date.UTC(fy, fm - 1, fd)) / 86_400_000,
    );
}

/** Separador de día: «Hoy», «Ayer», «lunes, 21 de septiembre» (con el año si no es este). */
export function formatDayLabel(value: string | Date, now = new Date()): string {
    const date = toDate(value);

    if (!date) {
        return '';
    }

    const key = dayKey(date);
    const today = dayKey(now);
    const diff = daysBetween(key, today);

    if (diff === 0) {
        return t('chat.day.today');
    }

    if (diff === 1) {
        return t('chat.day.yesterday');
    }

    const formatter =
        key.slice(0, 4) === today.slice(0, 4)
            ? longDayFormatter
            : longDayWithYearFormatter;

    return formatter.format(date);
}

/** Hora corta de la lista: hoy «14:05», ayer «Ayer», esta semana «lun.», antes «21/09/2026». */
export function formatListTime(
    value: string | Date | null,
    now = new Date(),
): string {
    const date = value === null ? null : toDate(value);

    if (!date) {
        return '';
    }

    const diff = daysBetween(dayKey(date), dayKey(now));

    if (diff <= 0) {
        return timeFormatter.format(date);
    }

    if (diff === 1) {
        return t('chat.day.yesterday');
    }

    if (diff < 7) {
        return weekdayFormatter.format(date);
    }

    return formatDate(date);
}

/** Fecha y hora completas para el atributo title de la hora de un mensaje. */
export function formatFullDateTime(value: string | null): string {
    const date = value === null ? null : toDate(value);

    return date
        ? `${longDayWithYearFormatter.format(date)}, ${timeFormatter.format(date)}`
        : '';
}

/** ¿Dos mensajes seguidos del mismo autor se agrupan? Mismo día y menos de 5 minutos. */
export function withinGroupWindow(previous: string, next: string): boolean {
    const a = toDate(previous);
    const b = toDate(next);

    return (
        a !== null &&
        b !== null &&
        dayKey(a) === dayKey(b) &&
        Math.abs(b.getTime() - a.getTime()) < 5 * 60_000
    );
}
