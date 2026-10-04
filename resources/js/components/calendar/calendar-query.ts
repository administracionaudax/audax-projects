/**
 * Calendario del equipo (D-144): vista, fecha y filtros en la URL, en español, como
 * App\Domain\Calendar\CalendarFilters, y cálculo de fechas de la navegación (semanas de lunes a
 * domingo; fechas locales "YYYY-MM-DD", nunca instantes).
 */
import {
    daysBetween,
    monthLabel,
    monthOf,
    shiftMonth,
} from '@/components/planning/calendar-dates';
import { weekRangeLabel } from '@/components/time/week-days';
import { weekdayLongLabel } from '@/components/time/week-days';
import { formatDate } from '@/lib/format';
import { addDays, weekStart } from '@/lib/week';
import type {
    TeamCalendarFilters,
    TeamCalendarTask,
    TeamCalendarView,
} from '@/types/calendar';

const VIEW_PARAM: Record<TeamCalendarView, string> = {
    month: 'mes',
    week: 'semana',
    day: 'dia',
};

export const DEFAULT_VIEW: TeamCalendarView = 'week';

/** Parámetros que cuentan como filtros o vista (para recordarlos y restaurarlos). */
export const CALENDAR_PARAMS = [
    'vista',
    'personas',
    'persona',
    'departamento',
    'proyecto',
    'cliente',
    'hechas',
    'prioridad',
    'tipo',
    'hitos',
    'sin_asignar',
    'mias',
    'q',
] as const;

/** Filtros (sin la vista ni la fecha). */
export type CalendarFilterFields = Omit<
    TeamCalendarFilters,
    'view' | 'people' | 'date'
>;

export const EMPTY_CALENDAR_FILTERS: CalendarFilterFields = {
    persons: [],
    department: null,
    projects: [],
    clients: [],
    done: false,
    priority: null,
    types: [],
    milestones: false,
    unassigned: false,
    mine: false,
    q: null,
};

export function hasCalendarFilters(filters: TeamCalendarFilters): boolean {
    return countCalendarFilters(filters) > 0;
}

export function countCalendarFilters(filters: TeamCalendarFilters): number {
    return [
        filters.persons.length > 0,
        filters.department !== null,
        filters.projects.length > 0,
        filters.clients.length > 0,
        filters.done,
        filters.priority !== null,
        filters.types.length > 0,
        filters.milestones,
        filters.unassigned,
        filters.mine,
        (filters.q ?? '') !== '',
    ].filter(Boolean).length;
}

/**
 * Vista y filtros → parámetros de la URL (lo que se recuerda): sin los vacíos ni la vista por
 * defecto. La fecha va aparte (calendarQuery), porque al volver se empieza por hoy.
 */
export function calendarFilterQuery(
    filters: TeamCalendarFilters,
): Record<string, string> {
    const query: Record<string, string> = {};

    if (filters.view !== DEFAULT_VIEW) {
        query.vista = VIEW_PARAM[filters.view];
    }

    if (filters.people && filters.view !== 'month') {
        query.personas = '1';
    }

    const lists: [keyof TeamCalendarFilters, string][] = [
        ['persons', 'persona'],
        ['projects', 'proyecto'],
        ['clients', 'cliente'],
        ['types', 'tipo'],
    ];

    for (const [field, param] of lists) {
        const ids = filters[field] as number[];

        if (ids.length > 0) {
            query[param] = ids.join(',');
        }
    }

    if (filters.department !== null) {
        query.departamento = String(filters.department);
    }

    if (filters.done) {
        query.hechas = '1';
    }

    if (filters.priority !== null) {
        query.prioridad = filters.priority;
    }

    if (filters.milestones) {
        query.hitos = '1';
    }

    if (filters.unassigned) {
        query.sin_asignar = '1';
    }

    if (filters.mine) {
        query.mias = '1';
    }

    const q = (filters.q ?? '').trim();

    if (q !== '') {
        query.q = q;
    }

    return query;
}

/** Parámetros completos: los filtros y la fecha (si no es hoy). */
export function calendarQuery(
    filters: TeamCalendarFilters,
    today: string,
): Record<string, string> {
    return {
        ...calendarFilterQuery(filters),
        ...(filters.date !== today ? { fecha: filters.date } : {}),
    };
}

/** La fecha al ir al periodo anterior (-1) o siguiente (+1). */
export function shiftDate(
    view: TeamCalendarView,
    date: string,
    delta: number,
): string {
    if (view === 'day') {
        return addDays(date, delta);
    }

    if (view === 'week') {
        return addDays(weekStart(date), 7 * delta);
    }

    return `${shiftMonth(monthOf(date), delta)}-01`;
}

/** «octubre de 2026», «5 oct – 11 oct 2026» o «miércoles, 07/10/2026». */
export function calendarTitle(
    view: TeamCalendarView,
    date: string,
    from: string,
    to: string,
): string {
    if (view === 'month') {
        return monthLabel(monthOf(date));
    }

    if (view === 'week') {
        return weekRangeLabel(from, to);
    }

    return `${weekdayLongLabel(date)}, ${formatDate(date)}`;
}

/** Días de `from` a `to`, ambos incluidos. */
export function daysOf(from: string, to: string): string[] {
    const days: string[] = [];

    for (let day = from; day <= to; day = addDays(day, 1)) {
        days.push(day);
    }

    return days;
}

/** Primer y último día de la tarea (con una sola fecha, ese día). */
export function taskSpan(
    task: Pick<TeamCalendarTask, 'start_date' | 'due_date'>,
): { first: string; last: string } | null {
    const first = task.start_date ?? task.due_date;
    const last = task.due_date ?? task.start_date;

    if (first === null || last === null) {
        return null;
    }

    return first <= last ? { first, last } : { first: last, last: first };
}

/** ¿La tarea ocupa ese día (de su inicio a su entrega)? */
export function taskOnDay(
    task: Pick<TeamCalendarTask, 'start_date' | 'due_date'>,
    day: string,
): boolean {
    const span = taskSpan(task);

    return span !== null && span.first <= day && span.last >= day;
}

/**
 * Día en el que la tarea tiene su tarjeta (no su franja): la entrega o, sin ella, el inicio.
 */
export function anchorDay(
    task: Pick<TeamCalendarTask, 'start_date' | 'due_date'>,
): string | null {
    return task.due_date ?? task.start_date;
}

/** ¿Es una tarea de rango (inicio anterior a la entrega)? */
export function isRange(
    task: Pick<TeamCalendarTask, 'start_date' | 'due_date'>,
): boolean {
    return (
        task.start_date !== null &&
        task.due_date !== null &&
        task.start_date < task.due_date
    );
}

/**
 * Fechas nuevas al llevar la tarea a otro día (D-144): se mueven el inicio y la entrega lo mismo,
 * así que conserva su duración. Con una sola fecha, se mueve esa. Un hito solo tiene entrega.
 */
export function movedTeamDates(
    task: Pick<TeamCalendarTask, 'start_date' | 'due_date' | 'is_milestone'>,
    newDay: string,
): { start_date: string | null; due_date: string | null } {
    if (task.is_milestone || task.start_date === null) {
        return { start_date: null, due_date: newDay };
    }

    if (task.due_date === null) {
        return { start_date: newDay, due_date: null };
    }

    const delta = daysBetween(task.due_date, newDay);

    return {
        start_date: addDays(task.start_date, delta),
        due_date: newDay,
    };
}
