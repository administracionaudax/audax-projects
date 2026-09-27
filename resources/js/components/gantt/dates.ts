/**
 * Fechas de calendario "YYYY-MM-DD" del Gantt como números de día (días desde el 01/01/1970).
 * Se calcula en UTC solo como aritmética: NUNCA hay conversión de zona horaria, así que un día es
 * siempre un día (también en los cambios de horario de verano). La semana empieza en lunes.
 */

const DAY_MS = 86_400_000;

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

export function isDate(value: unknown): value is string {
    return typeof value === 'string' && DATE.test(value);
}

/** "2026-10-05" → número de día. */
export function toDay(date: string): number {
    const match = DATE.exec(date);

    if (!match) {
        throw new RangeError(`Fecha no válida: ${date}`);
    }

    return Math.round(
        Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) /
            DAY_MS,
    );
}

/** Número de día → "2026-10-05". */
export function fromDay(day: number): string {
    return new Date(day * DAY_MS).toISOString().slice(0, 10);
}

export function addDays(date: string, days: number): string {
    return fromDay(toDay(date) + days);
}

/** Días de `from` a `to` (negativo si `to` es anterior). */
export function diffDays(from: string, to: string): number {
    return toDay(to) - toDay(from);
}

/** Día de la semana con el lunes = 0 y el domingo = 6. */
export function weekdayIndex(date: string): number {
    // El 01/01/1970 fue jueves (3 con el lunes = 0).
    return (((toDay(date) + 3) % 7) + 7) % 7;
}

export function isWeekend(date: string): boolean {
    return weekdayIndex(date) >= 5;
}

/** Lunes de la semana de la fecha. */
export function startOfWeek(date: string): string {
    return addDays(date, -weekdayIndex(date));
}

/** Domingo de la semana de la fecha. */
export function endOfWeek(date: string): string {
    return addDays(startOfWeek(date), 6);
}

export function startOfMonth(date: string): string {
    return `${date.slice(0, 7)}-01`;
}

/** Días del mes (28 a 31); `month` de 1 a 12. */
export function daysInMonth(year: number, month: number): number {
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

export function endOfMonth(date: string): string {
    const year = Number(date.slice(0, 4));
    const month = Number(date.slice(5, 7));

    return `${date.slice(0, 7)}-${String(daysInMonth(year, month)).padStart(2, '0')}`;
}

/** Primer día del mes siguiente. */
export function nextMonth(date: string): string {
    return addDays(endOfMonth(date), 1);
}

export function startOfYear(date: string): string {
    return `${date.slice(0, 4)}-01-01`;
}

export function endOfYear(date: string): string {
    return `${date.slice(0, 4)}-12-31`;
}

export function minDate(a: string, b: string): string {
    return a <= b ? a : b;
}

export function maxDate(a: string, b: string): string {
    return a >= b ? a : b;
}
