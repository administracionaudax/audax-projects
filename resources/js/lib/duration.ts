/**
 * Duraciones del campo de horas (SPEC §7, D-036). Gemelo de App\Support\Duration:
 * ambos pasan los mismos casos (tests/fixtures/duration-cases.json).
 *
 * Acepta "1:30", "1.5", "1,5", "90m", "90min", "1h30", "1h 30m", "2h" y "2" (horas).
 * Devuelve minutos enteros (1 a `max`, por defecto 1440 = 24 h) o null si no es válida.
 * Las estimaciones de tareas y los totales de bolsa pasan un `max` mayor.
 */
export const MAX_MINUTES = 24 * 60;

export function parseDuration(
    input: string | null | undefined,
    max: number = MAX_MINUTES,
): number | null {
    const text = (input ?? '').trim().toLowerCase().replace(/\s+/gu, '');

    if (text === '') {
        return null;
    }

    let minutes: number | null = null;
    let match: RegExpExecArray | null;

    if ((match = /^(\d{1,4}):([0-5]\d)$/.exec(text))) {
        minutes = Number(match[1]) * 60 + Number(match[2]);
    } else if ((match = /^(\d+)(?:m|min)$/.exec(text))) {
        minutes = Number(match[1]);
    } else if ((match = /^(\d+)h(?:([0-5]?\d)(?:m|min)?)?$/.exec(text))) {
        minutes = Number(match[1]) * 60 + Number(match[2] ?? 0);
    } else if ((match = /^(\d+(?:[.,]\d+)?)h?$/.exec(text))) {
        minutes = Math.round(Number(match[1].replace(',', '.')) * 60);
    }

    if (
        minutes === null ||
        !Number.isFinite(minutes) ||
        minutes <= 0 ||
        minutes > max
    ) {
        return null;
    }

    return minutes;
}

/** Redondea al múltiplo más cercano de `step` (temporizador). Igual que Duration::roundToNearest. */
export function roundToNearest(minutes: number, step: number): number {
    if (step <= 1) {
        return minutes;
    }

    return Math.round(minutes / step) * step;
}

const CLOCK = /^([01]\d|2[0-3]):([0-5]\d)$/u;

/** Franja horaria de una entrada manual (D-162): sus minutos o por qué no vale. */
export type TimeRangeResult =
    | { minutes: number }
    | { error: 'format' | 'empty' | 'midnight' };

/**
 * Minutos entre dos horas "HH:MM" del mismo día (gemelo de App\Domain\Time\TimeRange). «00:00»
 * como fin es la medianoche que cierra el día (24:00). Una franja que cruza la medianoche no vale:
 * se registra en dos entradas. Es la hora de reloj: el servidor cuenta el tiempo real en los dos
 * días del año con cambio de hora.
 */
export function timeRangeMinutes(start: string, end: string): TimeRangeResult {
    const from = CLOCK.exec(start);
    const to = CLOCK.exec(end);

    if (!from || !to) {
        return { error: 'format' };
    }

    const startMinutes = Number(from[1]) * 60 + Number(from[2]);
    const endMinutes =
        end === '00:00' ? MAX_MINUTES : Number(to[1]) * 60 + Number(to[2]);

    if (end !== '00:00' && endMinutes <= startMinutes) {
        return { error: endMinutes === startMinutes ? 'empty' : 'midnight' };
    }

    return { minutes: endMinutes - startMinutes };
}
