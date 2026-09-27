/**
 * Geometría del Gantt (D-060), pura y sin DOM: fechas "YYYY-MM-DD" ↔ píxeles en las escalas día,
 * semana y mes, cabeceras, fines de semana, arrastre por días y rutas de las flechas. Las fechas
 * se tratan como números de día (./dates): nunca hay conversión de zona horaria.
 */
import {
    addDays,
    diffDays,
    endOfMonth,
    endOfWeek,
    endOfYear,
    fromDay,
    maxDate,
    minDate,
    nextMonth,
    startOfMonth,
    startOfWeek,
    startOfYear,
    toDay,
    weekdayIndex,
} from '@/components/gantt/dates';
import type {
    GanttDates,
    GanttRange,
    GanttScale,
} from '@/components/gantt/types';
import { LOCALE } from '@/lib/format';

/** Ancho de un día en cada escala: una columna por día, 112 px por semana o ~120 px por mes. */
export const DAY_WIDTH: Record<GanttScale, number> = {
    day: 32,
    week: 16,
    month: 4,
};

export const ROW_HEIGHT = 36;
export const BAR_HEIGHT = 20;
export const SUMMARY_HEIGHT = 8;
export const MILESTONE_SIZE = 14;
export const HEADER_TIER_HEIGHT = 26;
export const HEADER_HEIGHT = HEADER_TIER_HEIGHT * 2;

/** Píxeles que hay que arrastrar antes de empezar a mover (para distinguirlo de un clic). */
export const DRAG_THRESHOLD = 3;

/**
 * Ancho mínimo para agarrar una barra con el ratón o el dedo: las más estrechas (una tarea de un
 * día en la escala mes mide 4 px) amplían su zona sensible hasta aquí, centrada en la barra.
 */
export const MIN_GRAB_WIDTH = 16;

/** Por debajo de este ancho, la barra no tiene tiradores: se mueve entera (y se redimensiona con el teclado). */
export const MIN_RESIZE_WIDTH = 16;

/** Ancho máximo de cada tirador y lo que sobresale de la barra (en las barras anchas). */
const HANDLE_MAX = 10;
const HANDLE_OVERHANG = 4;

export type ResizeHandle = {
    /** Ancho de cada tirador. */
    size: number;
    /** Lo que sobresale por fuera de la barra. */
    overhang: number;
};

/**
 * Tiradores de los bordes de una barra: como mucho un cuarto de su ancho cada uno, para que
 * siempre quede al menos la mitad central para moverla. Las barras muy estrechas no tienen.
 */
export function resizeHandle(width: number): ResizeHandle | null {
    if (width < MIN_RESIZE_WIDTH) {
        return null;
    }

    const size = Math.min(HANDLE_MAX, Math.floor(width / 4));

    return { size, overhang: size >= HANDLE_MAX ? HANDLE_OVERHANG : 0 };
}

export type Timeline = {
    scale: GanttScale;
    /** Primer día visible (alineado a la unidad de la escala). */
    start: string;
    /** Último día visible (incluido). */
    end: string;
    dayWidth: number;
    startDay: number;
    days: number;
    width: number;
};

/**
 * Alinea el rango a la escala: la semana, de lunes a domingo; el mes, del día 1 al último; en la
 * escala mes, a meses completos también.
 */
export function alignRange(range: GanttRange, scale: GanttScale): GanttRange {
    const start = minDate(range.start, range.end);
    const end = maxDate(range.start, range.end);

    if (scale === 'week') {
        return { start: startOfWeek(start), end: endOfWeek(end) };
    }

    if (scale === 'month') {
        return { start: startOfMonth(start), end: endOfMonth(end) };
    }

    return { start, end };
}

export function createTimeline(range: GanttRange, scale: GanttScale): Timeline {
    const aligned = alignRange(range, scale);
    const dayWidth = DAY_WIDTH[scale];
    const days = diffDays(aligned.start, aligned.end) + 1;

    return {
        scale,
        start: aligned.start,
        end: aligned.end,
        dayWidth,
        startDay: toDay(aligned.start),
        days,
        width: days * dayWidth,
    };
}

/** Borde izquierdo del día. */
export function dateToX(timeline: Timeline, date: string): number {
    return (toDay(date) - timeline.startDay) * timeline.dayWidth;
}

/** Centro del día. */
export function dayCenterX(timeline: Timeline, date: string): number {
    return dateToX(timeline, date) + timeline.dayWidth / 2;
}

/** Día que contiene la coordenada x. */
export function xToDate(timeline: Timeline, x: number): string {
    return fromDay(timeline.startDay + Math.floor(x / timeline.dayWidth));
}

/** Rango de días de una marca (los dos extremos incluidos). */
export type Span = { start: string; end: string };

/**
 * Días que ocupa una tarea: del inicio a la entrega; con una sola fecha, ese día; un hito, el día
 * de su entrega (o de su inicio si solo tiene ese). Sin fechas, null.
 */
export function taskSpan(dates: GanttDates, milestone = false): Span | null {
    const { start_date: start, due_date: due } = dates;

    if (milestone) {
        const day = due ?? start;

        return day ? { start: day, end: day } : null;
    }

    if (start && due) {
        return { start: minDate(start, due), end: maxDate(start, due) };
    }

    const day = due ?? start;

    return day ? { start: day, end: day } : null;
}

/** Unión de varios rangos (null si no hay ninguno). */
export function unionSpan(spans: ReadonlyArray<Span | null>): Span | null {
    let result: Span | null = null;

    for (const span of spans) {
        if (!span) {
            continue;
        }

        result = result
            ? {
                  start: minDate(result.start, span.start),
                  end: maxDate(result.end, span.end),
              }
            : span;
    }

    return result;
}

export type Box = { x: number; width: number };

/** Caja horizontal de una barra: del borde izquierdo del primer día al derecho del último. */
export function spanBox(timeline: Timeline, span: Span): Box {
    return {
        x: dateToX(timeline, span.start),
        width: (diffDays(span.start, span.end) + 1) * timeline.dayWidth,
    };
}

/** Caja del rombo de un hito, centrado en su día. */
export function milestoneBox(timeline: Timeline, date: string): Box {
    return {
        x: dayCenterX(timeline, date) - MILESTONE_SIZE / 2,
        width: MILESTONE_SIZE,
    };
}

export type DragMode = 'move' | 'start' | 'end';

/** Días enteros de un desplazamiento en píxeles (redondeado al día más cercano). */
export function dragDelta(dx: number, dayWidth: number): number {
    const days = Math.round(dx / dayWidth);

    return days === 0 ? 0 : days;
}

/**
 * Fechas tras mover (`move`) o cambiar el inicio (`start`) o la entrega (`end`) `delta` días.
 * - Mover desplaza las dos fechas (las que tenga).
 * - El inicio nunca pasa de la entrega, ni la entrega queda antes del inicio.
 * - Un hito solo tiene entrega: cualquier cambio mueve su entrega.
 * - Con una sola fecha, cambiar la otra la crea a partir de la que hay.
 */
export function applyDelta(
    dates: GanttDates,
    delta: number,
    mode: DragMode,
    milestone = false,
): GanttDates {
    const { start_date: start, due_date: due } = dates;

    if (delta === 0) {
        return { start_date: start, due_date: due };
    }

    if (milestone) {
        const day = due ?? start;

        return {
            start_date: null,
            due_date: day ? addDays(day, delta) : null,
        };
    }

    if (mode === 'move') {
        return {
            start_date: start ? addDays(start, delta) : null,
            due_date: due ? addDays(due, delta) : null,
        };
    }

    if (mode === 'end') {
        const base = due ?? start;

        if (!base) {
            return { start_date: start, due_date: due };
        }

        const next = addDays(base, delta);

        return {
            start_date: start,
            due_date: start ? maxDate(next, start) : next,
        };
    }

    const base = start ?? due;

    if (!base) {
        return { start_date: start, due_date: due };
    }

    const next = addDays(base, delta);

    return {
        start_date: due ? minDate(next, due) : next,
        due_date: due,
    };
}

export function sameDates(a: GanttDates, b: GanttDates): boolean {
    return a.start_date === b.start_date && a.due_date === b.due_date;
}

export type HeaderCell = {
    key: string;
    label: string;
    /** Texto completo (tooltip): «lunes, 5 de octubre de 2026», «semana del 5 al 11 de oct.»… */
    title: string;
    x: number;
    width: number;
    weekend?: boolean;
};

const monthLong = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    timeZone: 'UTC',
});
const monthYear = new Intl.DateTimeFormat(LOCALE, {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});
const monthShort = new Intl.DateTimeFormat(LOCALE, {
    month: 'short',
    timeZone: 'UTC',
});
const weekdayNarrow = new Intl.DateTimeFormat(LOCALE, {
    weekday: 'narrow',
    timeZone: 'UTC',
});
const fullDate = new Intl.DateTimeFormat(LOCALE, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});
const dayMonth = new Intl.DateTimeFormat(LOCALE, {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

/** Fecha "YYYY-MM-DD" como Date a medianoche UTC (solo para formatear en UTC, sin conversión). */
function utc(date: string): Date {
    return new Date(toDay(date) * 86_400_000);
}

function clean(label: string): string {
    return label.replace(/\.$/, '');
}

export function formatMonthYear(date: string): string {
    return monthYear.format(utc(date));
}

export function formatDayMonth(date: string): string {
    return clean(dayMonth.format(utc(date)));
}

export function formatFullDate(date: string): string {
    return fullDate.format(utc(date));
}

/** Celdas entre `from` y `to` (incluidos) recortadas al rango visible. */
function cell(
    timeline: Timeline,
    key: string,
    from: string,
    to: string,
    label: string,
    title: string,
    weekend?: boolean,
): HeaderCell {
    const start = maxDate(from, timeline.start);
    const end = minDate(to, timeline.end);
    const box = spanBox(timeline, { start, end });

    return { key, label, title, x: box.x, width: box.width, weekend };
}

function monthCells(timeline: Timeline, withYear: boolean): HeaderCell[] {
    const cells: HeaderCell[] = [];

    for (
        let month = startOfMonth(timeline.start);
        month <= timeline.end;
        month = nextMonth(month)
    ) {
        const label = withYear
            ? monthYear.format(utc(month))
            : monthLong.format(utc(month));

        cells.push(
            cell(
                timeline,
                `m-${month}`,
                month,
                endOfMonth(month),
                label,
                monthYear.format(utc(month)),
            ),
        );
    }

    return cells;
}

/**
 * Cabecera en dos niveles:
 * - día: meses arriba y días (inicial del día de la semana y número) abajo,
 * - semana: meses arriba y semanas (desde su lunes) abajo,
 * - mes: años arriba y meses abajo.
 */
export function headerCells(timeline: Timeline): {
    top: HeaderCell[];
    bottom: HeaderCell[];
} {
    if (timeline.scale === 'month') {
        const years: HeaderCell[] = [];

        for (
            let year = startOfYear(timeline.start);
            year <= timeline.end;
            year = addDays(endOfYear(year), 1)
        ) {
            const label = year.slice(0, 4);
            years.push(
                cell(
                    timeline,
                    `y-${label}`,
                    year,
                    endOfYear(year),
                    label,
                    label,
                ),
            );
        }

        const months = monthCells(timeline, false).map((month) => ({
            ...month,
            label: clean(monthShort.format(utc(month.key.slice(2)))),
        }));

        return { top: years, bottom: months };
    }

    const top = monthCells(timeline, true);
    const bottom: HeaderCell[] = [];

    if (timeline.scale === 'week') {
        for (
            let monday = startOfWeek(timeline.start);
            monday <= timeline.end;
            monday = addDays(monday, 7)
        ) {
            const sunday = addDays(monday, 6);
            bottom.push(
                cell(
                    timeline,
                    `w-${monday}`,
                    monday,
                    sunday,
                    formatDayMonth(monday),
                    `${formatFullDate(monday)} – ${formatFullDate(sunday)}`,
                ),
            );
        }

        return { top, bottom };
    }

    for (let day = 0; day < timeline.days; day++) {
        const date = fromDay(timeline.startDay + day);
        const weekday = weekdayIndex(date);

        bottom.push({
            key: `d-${date}`,
            label: `${weekdayNarrow.format(utc(date)).toUpperCase()} ${Number(date.slice(8))}`,
            title: formatFullDate(date),
            x: day * timeline.dayWidth,
            width: timeline.dayWidth,
            weekend: weekday >= 5,
        });
    }

    return { top, bottom };
}

/** Bandas de fin de semana (sábado y domingo), solo en la escala día. */
export function weekendBands(timeline: Timeline): Box[] {
    if (timeline.scale !== 'day') {
        return [];
    }

    const bands: Box[] = [];
    const firstWeekday = weekdayIndex(timeline.start);
    // Primer sábado dentro del rango (o el domingo inicial si empieza en domingo).
    let day = firstWeekday === 6 ? -1 : 5 - firstWeekday;

    for (; day < timeline.days; day += 7) {
        const from = Math.max(day, 0);
        const to = Math.min(day + 1, timeline.days - 1);

        if (to >= from) {
            bands.push({
                x: from * timeline.dayWidth,
                width: (to - from + 1) * timeline.dayWidth,
            });
        }
    }

    return bands;
}

/** Líneas verticales de la rejilla: el borde izquierdo de cada celda del nivel inferior. */
export function gridLines(timeline: Timeline): number[] {
    return headerCells(timeline)
        .bottom.map((header) => header.x)
        .filter((x) => x > 0);
}

export type Point = { x: number; y: number };

/**
 * Flecha fin → inicio con tramos ortogonales: sale a la derecha del final de la predecesora y
 * entra por la izquierda del inicio de la sucesora. Si la sucesora empieza antes, rodea por el
 * hueco entre las dos filas.
 */
export function dependencyPath(
    from: Point,
    to: Point,
    gap = 8,
    rowHeight = ROW_HEIGHT,
): string {
    if (to.x - from.x >= gap * 2) {
        const bend = from.x + gap;

        return from.y === to.y
            ? `M ${from.x} ${from.y} H ${to.x}`
            : `M ${from.x} ${from.y} H ${bend} V ${to.y} H ${to.x}`;
    }

    const between =
        to.y >= from.y ? from.y + rowHeight / 2 : from.y - rowHeight / 2;

    return [
        `M ${from.x} ${from.y}`,
        `H ${from.x + gap}`,
        `V ${between}`,
        `H ${to.x - gap}`,
        `V ${to.y}`,
        `H ${to.x}`,
    ].join(' ');
}

/** Punto medio aproximado de una flecha (donde va el botón para quitarla). */
export function dependencyMidpoint(
    from: Point,
    to: Point,
    gap = 8,
    rowHeight = ROW_HEIGHT,
): Point {
    if (to.x - from.x >= gap * 2) {
        return from.y === to.y
            ? { x: (from.x + to.x) / 2, y: from.y }
            : { x: from.x + gap, y: (from.y + to.y) / 2 };
    }

    return {
        x: (from.x + to.x) / 2,
        y: to.y >= from.y ? from.y + rowHeight / 2 : from.y - rowHeight / 2,
    };
}

/** Día que queda en el borde izquierdo de la parte visible del diagrama. */
export function dateAtScroll(timeline: Timeline, scrollLeft: number): string {
    return xToDate(timeline, Math.max(scrollLeft, 0));
}
