/**
 * Plan del día (D-250): funciones puras de la pantalla «Mi día», con sus tests en
 * tests/js/day-plan-lib.test.ts.
 *
 * - Atajos al escribir una línea (docs/PLAN-CARGAS.md §4.2): `@cliente` y `#proyecto` abren el
 *   selector; `~1:30` pone las horas previstas (el parser de duración de siempre).
 * - Etiquetas de los días («hoy», «mañana», «el lunes»…) y del aviso de pendientes.
 */
import { parseDuration } from '@/lib/duration';
import { t } from '@/lib/i18n';
import { addDays } from '@/lib/week';
import { weekdayLongLabel } from '@/components/time/week-days';
import type {
    DayPlanPendingLine,
    DayPlanTargetClient,
    DayPlanTargetProject,
    DayPlanTargets,
} from '@/types/day-plan';

/** Minúsculas y sin acentos, para buscar «diseno» en «Diseño». */
export function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/gu, '')
        .toLocaleLowerCase('es');
}

export type ActiveToken = {
    kind: '@' | '#';
    query: string;
    /** Posición del `@` o el `#`. */
    start: number;
    /** Fin del atajo (el cursor). */
    end: number;
};

/**
 * El atajo que se está escribiendo justo antes del cursor: un `@` o un `#` al principio o tras un
 * espacio, seguido de letras sin espacios (como mucho dos palabras con espacio no se admiten: se
 * elige de la lista). null si no hay ninguno.
 */
export function activeToken(text: string, caret: number): ActiveToken | null {
    const before = text.slice(0, caret);
    const match = /(^|\s)([@#])([^\s@#~]*)$/u.exec(before);

    if (!match) {
        return null;
    }

    const start = before.length - match[3].length - 1;

    return {
        kind: match[2] as '@' | '#',
        query: match[3],
        start,
        end: caret,
    };
}

/** Quita el atajo [start, end) y deja un solo espacio donde estaba. */
export function removeToken(text: string, token: ActiveToken): string {
    return `${text.slice(0, token.start)}${text.slice(token.end)}`
        .replace(/\s{2,}/gu, ' ')
        .replace(/^\s+/u, '');
}

export type MatchedTarget =
    | { type: 'client'; client: DayPlanTargetClient }
    | { type: 'project'; project: DayPlanTargetProject };

/**
 * Coincidencias del selector: con `@`, clientes por nombre; con `#`, proyectos por código, nombre o
 * cliente (los míos primero, como llegan). Como mucho `limit`.
 */
export function matchTargets(
    kind: '@' | '#',
    query: string,
    targets: DayPlanTargets | undefined,
    limit = 8,
): MatchedTarget[] {
    if (!targets) {
        return [];
    }

    const needle = normalize(query);

    if (kind === '@') {
        return targets.clients
            .filter((client) => normalize(client.name).includes(needle))
            .sort(
                (a, b) =>
                    Number(!normalize(a.name).startsWith(needle)) -
                    Number(!normalize(b.name).startsWith(needle)),
            )
            .slice(0, limit)
            .map((client) => ({ type: 'client' as const, client }));
    }

    return targets.projects
        .filter((project) =>
            normalize(
                `${project.code} ${project.name} ${project.client_name ?? ''}`,
            ).includes(needle),
        )
        .slice(0, limit)
        .map((project) => ({ type: 'project' as const, project }));
}

export type ParsedLine = {
    text: string;
    /** Minutos de `~1:30`; null si no hay atajo. */
    minutes: number | null;
    /** El atajo `~` no se entiende. */
    invalidDuration: boolean;
};

/**
 * Separa las horas previstas (`~1:30`, `~1.5`, `~90m`) del texto. El resto queda tal cual (los `@` y
 * `#` sin elegir de la lista son texto normal: «Reunión @ 10»).
 */
export function parseLine(input: string): ParsedLine {
    let minutes: number | null = null;
    let invalidDuration = false;

    const text = input
        .replace(/(^|\s)~(\S+)/gu, (_all, space: string, value: string) => {
            const parsed = parseDuration(value);

            if (parsed === null) {
                invalidDuration = true;

                return `${space}~${value}`;
            }

            minutes = parsed;

            return space;
        })
        .replace(/\s{2,}/gu, ' ')
        .trim();

    return { text, minutes, invalidDuration };
}

/** «hoy», «mañana», «ayer» o «el lunes 12/10». */
export function dayLabel(date: string, today: string): string {
    if (date === today) {
        return t('day_plan.dates.today');
    }

    if (date === addDays(today, 1)) {
        return t('day_plan.dates.tomorrow');
    }

    if (date === addDays(today, -1)) {
        return t('day_plan.dates.yesterday');
    }

    const [, month, day] = date.split('-');

    return t('day_plan.dates.weekday', {
        weekday: weekdayLongLabel(date),
        date: `${day}/${month}`,
    });
}

/** «Tienes 2 pendientes del lunes» o «Tienes 3 pendientes de días anteriores». */
export function pendingLabel(
    pending: DayPlanPendingLine[],
    today: string,
): string {
    const dates = [...new Set(pending.map((line) => line.date))];

    if (dates.length === 1) {
        const one = pending.length === 1;

        if (dates[0] === addDays(today, -1)) {
            return one
                ? t('day_plan.pending.one_yesterday')
                : t('day_plan.pending.many_yesterday', {
                      count: pending.length,
                  });
        }

        return t(
            one
                ? 'day_plan.pending.one_weekday'
                : 'day_plan.pending.many_weekday',
            { count: pending.length, day: weekdayLongLabel(dates[0]) },
        );
    }

    return t('day_plan.pending.many_previous', { count: pending.length });
}

/** «↻ ×2» como texto accesible: «Viene arrastrada de 2 días». */
export function carryLabel(count: number): string {
    return count === 1
        ? t('day_plan.carry.one')
        : t('day_plan.carry.many', { count });
}

/** Las fechas a las que se puede pasar una línea: mañana, el próximo lunes y el resto hasta el horizonte. */
export function carryOptions(
    from: string,
    today: string,
    horizonEnd: string,
): string[] {
    const options: string[] = [];
    let day = today;

    while (day <= horizonEnd) {
        if (day !== from) {
            options.push(day);
        }

        day = addDays(day, 1);
    }

    return options;
}
