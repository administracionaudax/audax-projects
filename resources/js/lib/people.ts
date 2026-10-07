/**
 * Utilidades puras del registro de jornada (Fase 11, R1): textos de estado, tramos del día, la
 * diferencia con signo, el tiempo trabajado en vivo y las filas del formulario de corrección. Sin
 * React, para poder probarlas con Vitest (tests/js/people.test.ts).
 */
import { formatDate, formatMinutes, formatTime, TIME_ZONE } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    ClockKind,
    ClockShared,
    CorrectionRow,
    DayEvent,
    DayStatus,
    DayWorkday,
    WorkdayIncident,
    WorkMode,
} from '@/types/people';

/** Clave con el singular, el plural y (si la hay) la forma para 0: `x_zero`, `x_one`, `x_other`. */
export function tCount(
    base: string,
    count: number,
    replacements: Record<string, string | number> = {},
): string {
    const suffix = count === 0 ? '_zero' : count === 1 ? '_one' : '_other';
    const key = `${base}${suffix}`;
    const fallback = `${base}_other`;
    const chosen = (
        suffix === '_zero' && t(key as TranslationKey) === key ? fallback : key
    ) as TranslationKey;

    return t(chosen, { count, ...replacements });
}

/** Tono (clases de los tokens del tema) de cada estado del día: nunca solo el color. */
export const STATUS_TONE: Record<DayStatus, string> = {
    pending: 'bg-info-soft text-foreground',
    disputed: 'bg-warning-soft text-foreground',
    incident: 'bg-danger-soft text-foreground',
    warning: 'bg-warning-soft text-foreground',
    in_progress: 'bg-success-soft text-foreground',
    future: 'text-muted-foreground',
    off: 'text-muted-foreground',
    today: 'bg-neutral-soft text-foreground',
    ok: 'text-foreground',
};

export function statusLabel(status: DayStatus): string {
    return t(`people.status.${status}` as TranslationKey);
}

export function incidentLabel(incident: WorkdayIncident): string {
    return t(`people.incidents.${incident}` as TranslationKey);
}

export function kindLabel(kind: DayEvent['kind']): string {
    return t(`people.kinds.${kind}` as TranslationKey);
}

export function modeLabel(mode: WorkMode): string {
    return t(`people.modes.${mode}` as TranslationKey);
}

/** Incidencias que piden hacer algo (proponer una corrección). */
export const ACTION_INCIDENTS: readonly WorkdayIncident[] = [
    'missing_clock_out',
    'no_records',
    'pause_open',
];

/** 30 → "+0:30"; -15 → "-0:15"; 0 → "0:00"; null → "". */
export function formatDifference(minutes: number | null): string {
    if (minutes === null) {
        return '';
    }

    return minutes > 0 ? `+${formatMinutes(minutes)}` : formatMinutes(minutes);
}

/** Día de Madrid (AAAA-MM-DD) de un instante. */
export function madridDate(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(iso));
}

/**
 * Tramos de trabajo de las jornadas de un día: "09:02–14:00 · 15:01–18:10". Una salida de otro día
 * lleva «(+1)»; un tramo sin salida, «sin salida» (o «en curso» si la jornada sigue).
 */
export function segmentsLabel(workdays: DayWorkday[], date: string): string {
    const pieces: string[] = [];

    for (const workday of workdays) {
        for (const segment of workday.segments) {
            if (segment.kind !== 'work') {
                continue;
            }

            const from = formatTime(segment.from);
            let to: string;

            if (segment.to === null) {
                to =
                    workday.open && !workday.stale
                        ? t('people.workday.running')
                        : t('people.day.open_segment');
            } else {
                to = formatTime(segment.to);

                if (madridDate(segment.to) !== date) {
                    to = `${to} ${t('people.day.next_day')}`;
                }
            }

            pieces.push(`${from}–${to}`);
        }
    }

    return pieces.join(' · ');
}

/**
 * Segundos trabajados hoy en vivo: lo cerrado más el tramo en curso, con el reloj del dispositivo
 * corregido por la diferencia con el servidor (`offsetMs` = hora del servidor − hora local al
 * cargar la página).
 */
export function liveWorkedSeconds(
    clock: ClockShared,
    nowMs: number,
    offsetMs = 0,
): number {
    if (clock.running_since === null) {
        return clock.worked_seconds;
    }

    const running = Math.max(
        0,
        Math.floor(
            (nowMs + offsetMs - new Date(clock.running_since).getTime()) / 1000,
        ),
    );

    return clock.worked_seconds + running;
}

/** 'AAAA-MM' ± n meses. */
export function shiftMonth(month: string, delta: number): string {
    const [year, number] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + delta, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** «octubre de 2026». */
export function monthLabel(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Intl.DateTimeFormat('es-ES', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, number - 1, 1)));
}

/** «lun 5» para la lista del mes. */
export function shortDayLabel(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Intl.DateTimeFormat('es-ES', {
        weekday: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)));
}

/** «lunes, 5 de octubre de 2026». */
export function longDayLabel(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Intl.DateTimeFormat('es-ES', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)));
}

let keySeed = 0;

function rowKey(): string {
    keySeed += 1;

    return `row-${keySeed}`;
}

/** Las filas del formulario de corrección a partir de los fichajes efectivos del día. */
export function rowsFromEvents(
    events: Pick<DayEvent, 'id' | 'kind' | 'at' | 'work_mode'>[],
    date: string,
): CorrectionRow[] {
    return events
        .filter((event) => event.kind !== 'void')
        .map((event) => ({
            key: rowKey(),
            id: event.id,
            kind: event.kind as ClockKind,
            time: formatTime(event.at),
            next_day: madridDate(event.at) !== date,
            work_mode: event.work_mode,
        }));
}

/** Siguiente fichaje lógico tras la última fila (para «Añadir fichaje»). */
export function nextKind(rows: CorrectionRow[]): ClockKind {
    const last = rows[rows.length - 1]?.kind;

    switch (last) {
        case 'clock_in':
        case 'pause_end':
            return 'clock_out';
        case 'pause_start':
            return 'pause_end';
        default:
            return 'clock_in';
    }
}

/** Una fila nueva del formulario. */
export function newRow(
    kind: ClockKind,
    time = '',
    workMode: WorkMode | null = null,
): CorrectionRow {
    return {
        key: rowKey(),
        id: null,
        kind,
        time,
        next_day: false,
        work_mode:
            kind === 'clock_in' || kind === 'pause_end'
                ? (workMode ?? 'on_site')
                : null,
    };
}

/**
 * Cuántos cambios hay frente a los fichajes originales: los que se quitan o cambian (se anulan)
 * más los que se añaden o cambian (se añaden). Mismo criterio que ClockCorrectionService::diff.
 */
export function countChanges(
    original: CorrectionRow[],
    rows: CorrectionRow[],
): number {
    const byId = new Map(original.map((row) => [row.id, row]));
    const kept = new Set<number>();
    let adds = 0;

    for (const row of rows) {
        const before = row.id === null ? undefined : byId.get(row.id);

        if (
            before &&
            before.kind === row.kind &&
            before.time === row.time &&
            before.next_day === row.next_day &&
            (before.work_mode ?? null) === (row.work_mode ?? null)
        ) {
            kept.add(before.id as number);
        } else {
            adds += 1;
        }
    }

    const voids = original.filter((row) => !kept.has(row.id as number)).length;

    return voids + adds;
}

/** Lo que se envía al servidor (sin la clave de React). */
export function rowsPayload(rows: CorrectionRow[]): {
    id: number | null;
    kind: ClockKind;
    time: string;
    next_day: boolean;
    work_mode: WorkMode | null;
}[] {
    return rows.map(({ id, kind, time, next_day, work_mode }) => ({
        id,
        kind,
        time,
        next_day,
        work_mode:
            kind === 'clock_in' || kind === 'pause_end' ? work_mode : null,
    }));
}

/** «el 05/10/2026». */
export function dateLabel(date: string): string {
    return formatDate(date);
}
