/**
 * Reglas recurrentes (D-059) en el navegador: la frase legible («Cada 2 semanas, los lunes»,
 * «Cada mes, el día 31 (o el último)») y las fechas de sus tareas, para la vista previa en vivo
 * del formulario. Gemelo de App\Domain\Recurring\RecurrenceDescriber y de
 * App\Models\RecurringTaskRule::occurrencesBetween(). Trabaja con fechas "YYYY-MM-DD" sin zona.
 */
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { addDays } from '@/lib/week';
import type { RecurringFrequency } from '@/types/templates';

export type Recurrence = {
    frequency: RecurringFrequency;
    interval: number;
    /** 1 = lunes … 7 = domingo. */
    weekday: number | null;
    /** 1-31. */
    month_day: number | null;
    starts_on: string;
    ends_on: string | null;
};

/** Desde este día del mes, algún mes no lo tiene y se usa su último día. */
export const LAST_DAY_FROM = 29;

/** Horizonte de la próxima fecha: la repetición más larga (cada 12 meses) cabe siempre. */
export const NEXT_HORIZON_MONTHS = 13;

export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

const WEEKDAY_PLURAL: Record<number, TranslationKey> = {
    1: 'recurring.weekday_plural.1',
    2: 'recurring.weekday_plural.2',
    3: 'recurring.weekday_plural.3',
    4: 'recurring.weekday_plural.4',
    5: 'recurring.weekday_plural.5',
    6: 'recurring.weekday_plural.6',
    7: 'recurring.weekday_plural.7',
};

const WEEKDAY_NAME: Record<number, TranslationKey> = {
    1: 'recurring.weekday.1',
    2: 'recurring.weekday.2',
    3: 'recurring.weekday.3',
    4: 'recurring.weekday.4',
    5: 'recurring.weekday.5',
    6: 'recurring.weekday.6',
    7: 'recurring.weekday.7',
};

/** Nombre del día de la semana («lunes»), para los selectores. */
export function weekdayName(weekday: number): string {
    return t(WEEKDAY_NAME[clamp(weekday, 1, 7)]);
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(Math.trunc(value), min), max);
}

function parts(date: string): [number, number, number] {
    const [year, month, day] = date.split('-').map(Number);

    return [year, month, day];
}

function format(year: number, month: number, day: number): string {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** Día ISO de la semana (1 = lunes … 7 = domingo). */
export function isoWeekday(date: string): number {
    const [year, month, day] = parts(date);
    const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();

    return weekday === 0 ? 7 : weekday;
}

export function daysInMonth(year: number, month: number): number {
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

/** Suma meses al primer día de un mes ("YYYY-MM-01"). */
function addMonths(firstOfMonth: string, months: number): string {
    const [year, month] = parts(firstOfMonth);
    const index = year * 12 + (month - 1) + months;

    return format(Math.floor(index / 12), (index % 12) + 1, 1);
}

/** «Cada 2 semanas, los lunes» / «Cada mes, el día 31 (o el último)». */
export function describeRecurrence(
    rule: Pick<
        Recurrence,
        'frequency' | 'interval' | 'weekday' | 'month_day' | 'starts_on'
    >,
): string {
    const interval = Math.max(Math.trunc(rule.interval) || 1, 1);

    if (rule.frequency === 'weekly') {
        const weekday = clamp(rule.weekday ?? isoWeekday(rule.starts_on), 1, 7);

        return t('recurring.phrase.weekly', {
            every:
                interval === 1
                    ? t('recurring.phrase.every_week')
                    : t('recurring.phrase.every_n_weeks', { count: interval }),
            day: t(WEEKDAY_PLURAL[weekday]),
        });
    }

    const day = clamp(rule.month_day ?? parts(rule.starts_on)[2], 1, 31);

    return t(
        day >= LAST_DAY_FROM
            ? 'recurring.phrase.monthly_last'
            : 'recurring.phrase.monthly',
        {
            every:
                interval === 1
                    ? t('recurring.phrase.every_month')
                    : t('recurring.phrase.every_n_months', { count: interval }),
            day,
        },
    );
}

/**
 * Fechas de las tareas entre `from` y `to` (incluidas), respetando desde y hasta. Semanal: cada
 * N semanas desde la primera semana de la regla; mensual: cada N meses desde el mes de inicio, el
 * día elegido o el último del mes si no lo tiene.
 */
export function occurrencesBetween(
    rule: Recurrence,
    from: string,
    to: string,
): string[] {
    const start = rule.starts_on;
    const end = rule.ends_on !== null && rule.ends_on < to ? rule.ends_on : to;
    const interval = Math.max(Math.trunc(rule.interval) || 1, 1);
    const dates: string[] = [];

    if (rule.frequency === 'weekly') {
        const weekday = clamp(rule.weekday ?? isoWeekday(start), 1, 7);
        let cursor = addDays(start, weekday - isoWeekday(start));

        if (cursor < start) {
            cursor = addDays(cursor, 7 * interval);
        }

        for (; cursor <= end; cursor = addDays(cursor, 7 * interval)) {
            if (cursor >= from) {
                dates.push(cursor);
            }
        }

        return dates;
    }

    const monthDay = clamp(rule.month_day ?? parts(start)[2], 1, 31);
    const [startYear, startMonth] = parts(start);

    for (
        let month = format(startYear, startMonth, 1);
        month <= end;
        month = addMonths(month, interval)
    ) {
        const [year, monthNumber] = parts(month);
        const candidate = format(
            year,
            monthNumber,
            Math.min(monthDay, daysInMonth(year, monthNumber)),
        );

        if (candidate >= start && candidate >= from && candidate <= end) {
            dates.push(candidate);
        }
    }

    return dates;
}

/**
 * Próxima fecha en que se creará una tarea: hoy si toca (y no se ha generado ya), o la
 * siguiente. Null si no hay más (hasta `ends_on`).
 */
export function nextOccurrence(
    rule: Recurrence,
    today: string,
    lastGeneratedOn: string | null = null,
): string | null {
    const from =
        lastGeneratedOn !== null && lastGeneratedOn >= today
            ? addDays(today, 1)
            : today;
    const [year, month, day] = parts(from);
    const horizonMonth = addMonths(format(year, month, 1), NEXT_HORIZON_MONTHS);
    const [hYear, hMonth] = parts(horizonMonth);
    const horizon = format(
        hYear,
        hMonth,
        Math.min(day, daysInMonth(hYear, hMonth)),
    );

    return occurrencesBetween(rule, from, horizon)[0] ?? null;
}
