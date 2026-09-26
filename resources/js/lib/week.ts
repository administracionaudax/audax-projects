/**
 * Semanas ISO (lunes a domingo) para la hoja semanal (`/horas?semana=2026-W40`).
 * Trabaja con fechas locales "YYYY-MM-DD" sin zona: nunca convierte a UTC.
 */

const DAY_MS = 86_400_000;

function toUtcDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day));
}

function toDateString(date: Date): string {
    return date.toISOString().slice(0, 10);
}

/** Suma días a una fecha "YYYY-MM-DD". */
export function addDays(date: string, days: number): string {
    return toDateString(new Date(toUtcDate(date).getTime() + days * DAY_MS));
}

/** Lunes de la semana de una fecha. */
export function weekStart(date: string): string {
    const day = toUtcDate(date).getUTCDay(); // 0 = domingo

    return addDays(date, day === 0 ? -6 : 1 - day);
}

/** Los 7 días (lunes primero) de la semana que contiene la fecha. */
export function weekDays(date: string): string[] {
    const monday = weekStart(date);

    return Array.from({ length: 7 }, (_, index) => addDays(monday, index));
}

/** "2026-09-24" → "2026-W39" (año ISO de la semana). */
export function isoWeek(date: string): string {
    const target = toUtcDate(weekStart(date));
    // El jueves de la semana decide el año ISO.
    const thursday = new Date(target.getTime() + 3 * DAY_MS);
    const year = thursday.getUTCFullYear();
    const firstThursday = new Date(Date.UTC(year, 0, 4));
    const firstMonday = new Date(
        firstThursday.getTime() -
            ((firstThursday.getUTCDay() + 6) % 7) * DAY_MS,
    );
    const week =
        Math.round((target.getTime() - firstMonday.getTime()) / (7 * DAY_MS)) +
        1;

    return `${year}-W${String(week).padStart(2, '0')}`;
}

/** "2026-W39" → lunes "2026-09-21"; null si no es válido. */
export function weekFromIso(value: string): string | null {
    const match = /^(\d{4})-W(\d{2})$/.exec(value);

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const week = Number(match[2]);

    if (week < 1 || week > 53) {
        return null;
    }

    const firstThursday = new Date(Date.UTC(year, 0, 4));
    const firstMonday = new Date(
        firstThursday.getTime() -
            ((firstThursday.getUTCDay() + 6) % 7) * DAY_MS,
    );
    const monday = toDateString(
        new Date(firstMonday.getTime() + (week - 1) * 7 * DAY_MS),
    );

    return isoWeek(monday) === value ? monday : null;
}

/** Hoy en Europe/Madrid como "YYYY-MM-DD". */
export function todayInMadrid(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Madrid',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
}
