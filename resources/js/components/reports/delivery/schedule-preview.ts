/**
 * Envíos programados (D-141) en el navegador: la frase de la vista previa, en lenguaje natural
 * («El día 1 de cada mes a las 08:00, con el informe del mes anterior»), y las etiquetas del
 * periodo relativo según el periodo del informe (semana, mes, trimestre, año o rango). Las horas
 * son de Madrid, como en el servidor (App\Domain\Reports\Delivery\ScheduleClock).
 */
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    RelativePeriod,
    ScheduleFrequency,
} from '@/types/report-deliveries';
import type { ReportPeriod } from '@/types/reports';

export type ScheduleWhen = {
    frequency: ScheduleFrequency;
    run_date: string | null;
    weekday: number | null;
    month_day: number | null;
    time: string;
};

export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

/** Días del mes que se pueden elegir (1-28; 0 = el último). */
export const MONTH_DAYS = Array.from({ length: 28 }, (_, index) => index + 1);

export const LAST_DAY = 0;

const PERIODS: readonly ReportPeriod[] = [
    'semana',
    'mes',
    'trimestre',
    'anio',
    'rango',
];

/** El `periodo` de los filtros del informe; por defecto, el mes (como ReportFilters). */
export function reportPeriodOf(query: Record<string, unknown>): ReportPeriod {
    const value = query.periodo;

    return typeof value === 'string' &&
        (PERIODS as readonly string[]).includes(value)
        ? (value as ReportPeriod)
        : 'mes';
}

export function weekdayName(weekday: number): string {
    const day = Math.min(Math.max(Math.trunc(weekday), 1), 7);

    return t(`deliveries.weekday.${day}` as TranslationKey);
}

/** «El 05/10/2026 a las 08:00», «Cada lunes a las 08:00», «El día 1 de cada mes a las 08:00». */
export function describeWhen(when: ScheduleWhen): string {
    const time = when.time || '00:00';

    switch (when.frequency) {
        case 'once':
            return t('deliveries.preview.once', {
                date: when.run_date ? formatDate(when.run_date) : '…',
                time,
            });
        case 'weekly':
            return t('deliveries.preview.weekly', {
                weekday: weekdayName(when.weekday ?? 1),
                time,
            });
        case 'monthly':
            return when.month_day === LAST_DAY || when.month_day === null
                ? t('deliveries.preview.monthly_last', { time })
                : t('deliveries.preview.monthly', {
                      day: when.month_day,
                      time,
                  });
    }
}

/** «el informe del mes anterior», «el informe de la semana en curso», «el informe del periodo guardado». */
export function describeReport(
    relative: RelativePeriod,
    period: ReportPeriod,
): string {
    return relative === 'fixed'
        ? t('deliveries.report.fixed')
        : t(`deliveries.report.${relative}.${period}` as TranslationKey);
}

/** Etiqueta del selector de periodo relativo: «El mes anterior», «La semana en curso», «Fijo…». */
export function relativeLabel(
    relative: RelativePeriod,
    period: ReportPeriod,
): string {
    return relative === 'fixed'
        ? t('deliveries.relative.fixed')
        : t(`deliveries.relative.${relative}.${period}` as TranslationKey);
}

/** La frase completa de la vista previa. */
export function describeSchedule(
    when: ScheduleWhen,
    relative: RelativePeriod,
    period: ReportPeriod,
): string {
    return t('deliveries.preview.sentence', {
        when: describeWhen(when),
        report: describeReport(relative, period),
    });
}
