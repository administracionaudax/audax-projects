/**
 * Cálculos de fechas del calendario de tareas (D-061). Trabaja con fechas locales "YYYY-MM-DD"
 * (las fechas de tarea no tienen hora ni zona: nunca se convierten a UTC) y semanas de lunes a
 * domingo, como App\Domain\Planning\CalendarPeriod en el servidor.
 */
import { LOCALE } from '@/lib/format';
import { addDays, weekStart } from '@/lib/week';
import type { CalendarMode, CalendarTask } from '@/types/planning';

const DAY_MS = 86_400_000;

function toUtc(date: string): number {
    const [year, month, day] = date.split('-').map(Number);

    return Date.UTC(year, month - 1, day);
}

/** Días de `from` a `to` (negativo si `to` es anterior). */
export function daysBetween(from: string, to: string): number {
    return Math.round((toUtc(to) - toUtc(from)) / DAY_MS);
}

/** "2026-10-13" → "2026-10". */
export function monthOf(date: string): string {
    return date.slice(0, 7);
}

/** Suma meses a "2026-12" → "2027-01". */
export function shiftMonth(month: string, delta: number): string {
    const [year, value] = month.split('-').map(Number);
    const index = year * 12 + (value - 1) + delta;

    return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`;
}

/** Días del mes (28 a 31). */
export function daysInMonth(month: string): number {
    const [year, value] = month.split('-').map(Number);

    return new Date(Date.UTC(year, value, 0)).getUTCDate();
}

/**
 * Rejilla del mes: semanas completas de lunes a domingo, del lunes de la semana del día 1 al
 * domingo de la semana del último día (4, 5 o 6 filas).
 */
export function monthGrid(month: string): string[][] {
    const first = `${month}-01`;
    const last = `${month}-${String(daysInMonth(month)).padStart(2, '0')}`;
    const start = weekStart(first);
    const end = addDays(weekStart(last), 6);
    const weeks: string[][] = [];

    for (let monday = start; monday <= end; monday = addDays(monday, 7)) {
        weeks.push(Array.from({ length: 7 }, (_, day) => addDays(monday, day)));
    }

    return weeks;
}

/** Los 7 días (de lunes a domingo) de la semana que empieza en `monday`. */
export function weekOf(monday: string): string[] {
    return Array.from({ length: 7 }, (_, day) => addDays(monday, day));
}

/** Periodo que contiene una fecha: su mes o el lunes de su semana. */
export function periodContaining(mode: CalendarMode, date: string): string {
    return mode === 'month' ? monthOf(date) : weekStart(date);
}

/** Periodo anterior (-1) o siguiente (+1). */
export function shiftPeriod(
    mode: CalendarMode,
    period: string,
    delta: number,
): string {
    return mode === 'month'
        ? shiftMonth(period, delta)
        : addDays(period, 7 * delta);
}

/**
 * Periodo al cambiar de escala. A la semana: la de hoy si hoy cae en el mes que se ve; si no, la
 * del día 1. Al mes: el que contiene el jueves de la semana (la semana es del mes que tiene más
 * días de ella, como en las semanas ISO).
 */
export function modeSwitchPeriod(
    current: { mode: CalendarMode; period: string; today: string },
    next: CalendarMode,
): string {
    if (next === current.mode) {
        return current.period;
    }

    if (next === 'week') {
        return weekStart(
            monthOf(current.today) === current.period
                ? current.today
                : `${current.period}-01`,
        );
    }

    return monthOf(addDays(current.period, 3));
}

/** Parámetros de la URL del periodo (?mes=2026-10 o ?semana=2026-10-05). */
export function periodQuery(
    mode: CalendarMode,
    period: string,
): Record<string, string> {
    return mode === 'month' ? { mes: period } : { semana: period };
}

const monthFormatter = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

/** "2026-10" → "octubre de 2026". */
export function monthLabel(month: string): string {
    return monthFormatter.format(new Date(toUtc(`${month}-01`)));
}

/**
 * Fechas nuevas al llevar una tarea a otro día de entrega: el inicio se desplaza lo mismo, así que
 * conserva su duración (D-061). Sin entrega previa («Asignar fecha»), la entrega es ese día y el
 * inicio se queda si no es posterior (si lo es, pasa a ese mismo día). Un hito nunca tiene inicio.
 */
export function movedDates(
    task: Pick<CalendarTask, 'start_date' | 'due_date' | 'is_milestone'>,
    newDue: string,
): { start_date: string | null; due_date: string } {
    if (task.is_milestone) {
        return { start_date: null, due_date: newDue };
    }

    if (task.due_date === null) {
        return {
            start_date:
                task.start_date !== null && task.start_date > newDue
                    ? newDue
                    : task.start_date,
            due_date: newDue,
        };
    }

    const delta = daysBetween(task.due_date, newDue);

    return {
        start_date:
            task.start_date === null ? null : addDays(task.start_date, delta),
        due_date: newDue,
    };
}

/** Tareas por día de entrega (en el orden en que llegan del servidor). */
export function tasksByDueDate(
    tasks: CalendarTask[],
): Map<string, CalendarTask[]> {
    const byDay = new Map<string, CalendarTask[]>();

    for (const task of tasks) {
        if (task.due_date === null) {
            continue;
        }

        const list = byDay.get(task.due_date) ?? [];
        list.push(task);
        byDay.set(task.due_date, list);
    }

    return byDay;
}

export type WeekSpan = {
    task: CalendarTask;
    /** Columnas (0 = lunes) donde empieza y acaba la franja dentro de la semana. */
    startColumn: number;
    endColumn: number;
    /** Fila de la franja (se reparten para que no se solapen). */
    lane: number;
    /** La franja sigue antes del lunes o después del domingo. */
    continuesBefore: boolean;
    continuesAfter: boolean;
};

/**
 * Franjas (del inicio a la entrega) de las tareas con inicio anterior a la entrega que cruzan la
 * semana, repartidas en filas sin solaparse (la primera fila libre, por orden de inicio).
 */
export function weekSpans(tasks: CalendarTask[], days: string[]): WeekSpan[] {
    const monday = days[0];
    const sunday = days[days.length - 1];
    const spans = tasks
        .filter(
            (task) =>
                task.start_date !== null &&
                task.due_date !== null &&
                task.start_date < task.due_date &&
                task.start_date <= sunday &&
                task.due_date >= monday,
        )
        .map((task) => {
            const start = task.start_date as string;
            const due = task.due_date as string;

            return {
                task,
                startColumn: Math.max(0, daysBetween(monday, start)),
                endColumn: Math.min(6, daysBetween(monday, due)),
                continuesBefore: start < monday,
                continuesAfter: due > sunday,
            };
        })
        .sort(
            (a, b) =>
                a.startColumn - b.startColumn ||
                b.endColumn - a.endColumn ||
                a.task.id - b.task.id,
        );

    const laneEnds: number[] = [];

    return spans.map((span) => {
        let lane = laneEnds.findIndex((end) => end < span.startColumn);

        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(span.endColumn);
        } else {
            laneEnds[lane] = span.endColumn;
        }

        return { ...span, lane };
    });
}
